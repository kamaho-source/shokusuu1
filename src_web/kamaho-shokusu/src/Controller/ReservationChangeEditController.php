<?php
declare(strict_types=1);

namespace App\Controller;

use App\Application\Query\CalendarQueryInterface;
use App\Application\Query\ChangeEditQueryInterface;
use App\Application\Query\Dto\ChangeEditContext;
use App\Application\Query\Dto\ChangeEditMember;
use App\Application\Query\ReservationReadQueryInterface;
use App\Application\Query\RoomQueryInterface;
use App\Application\UseCase\RegisterIndividualReservation\RegisterIndividualReservationInput;
use App\Application\UseCase\RegisterIndividualReservation\RegisterIndividualReservationUseCase;
use App\Application\UseCase\SaveChangeEdit\SaveChangeEditInput;
use App\Application\UseCase\SaveChangeEdit\SaveChangeEditUseCase;
use App\Domain\Exception\NotFoundException as DomainNotFoundException;
use App\Domain\Service\DatePeriodRule;
use App\Infrastructure\Persistence\Entity\TReservationInfo;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Response;
use Cake\Http\ServerRequest;

/**
 * 直前編集（モーダルの枠・個人の保存・部屋の表の JSON・画面・まとめて保存）。
 * URL は /TReservationInfo/changeEdit・change-edit 等のまま（routes.php）、テンプレートは TReservationInfo/change_edit。
 *
 * @property \Authorization\Controller\Component\AuthorizationComponent $Authorization
 */
class ReservationChangeEditController extends ReservationBaseController
{
    use JsonResponseTrait;

    public function __construct(
        ServerRequest $request,
        CalendarQueryInterface $calendarQuery,
        RoomQueryInterface $roomQuery,
        ReservationReadQueryInterface $reservationRead,
        private readonly ChangeEditQueryInterface $changeEditQuery,
    ) {
        parent::__construct($request, $calendarQuery, $roomQuery, $reservationRead);
    }

    public function initialize(): void
    {
        parent::initialize();

        $this->viewBuilder()->setTemplatePath('TReservationInfo');
        $this->FormProtection->setConfig('unlockedActions', ['changeEdit']);
    }

    /**
     * 直前編集（モーダル用API/画面）。どの流れか（モーダルの枠・個人の保存・表の JSON・画面・まとめて保存）を決めるだけ。
     *
     * @param int|null $roomId
     * @param string|null $date YYYY-MM-DD
     * @param int|null $mealType 1=朝,2=昼,3=夜,4=弁当
     * @throws BadRequestException 画面で、部屋・日付が無い・日付が正しくない
     * @throws NotFoundException 画面で、部屋が無い
     * @throws \Throwable 画面で、それ以外の失敗（changeEditError がそのまま投げ直す）
     */
    public function changeEdit(RegisterIndividualReservationUseCase $registerIndividual, SaveChangeEditUseCase $saveChangeEdit, $roomId = null, $date = null, $mealType = null): ?Response
    {
        $wantsJson =
            $this->request->is('ajax') ||
            $this->request->getQuery('ajax') === '1' ||
            $this->request->accepts('application/json') ||
            $this->request->getParam('_ext') === 'json';

        if ($denied = $this->changeEditDeniedForNonMember($wantsJson) ?? $this->authorizeReservation('changeEdit', [], $wantsJson)) {
            return $denied;
        }

        try {
            $roomId   = $roomId   ?? $this->request->getParam('roomId')   ?? $this->request->getQuery('roomId')   ?? $this->request->getData('i_id_room');
            $date     = $date     ?? $this->request->getParam('date')     ?? $this->request->getQuery('date')     ?? $this->request->getData('d_reservation_date');
            // 暦にない日付は、以前は画面によって 200（空の表）・500 とばらばらだった。ここで 400 にそろえる
            if ($date !== null && $date !== '' && (!is_string($date) || !DatePeriodRule::isDate($date))) {
                throw new \InvalidArgumentException('日付が正しくありません。');
            }

            $login = $this->request->getAttribute('identity');
            $rooms = $this->roomQuery->getAccessibleRooms((int)$login?->get('i_id_user'));
            if ($this->request->is('get') && $this->queryString('modal') === '1') {
                return $this->changeEditModalShell($rooms, $roomId, $date);
            }
            $data = $this->request->is(['post', 'put']) ? $this->changeEditRequestData() : [];
            if (($data['reservation_type'] ?? '2') === '1') {
                return $this->saveChangeEditIndividual($registerIndividual, $data, $date, $rooms, $wantsJson);
            }

            if (!$roomId || !$date) {
                throw new \InvalidArgumentException('部屋IDまたは日付が指定されていません。');
            }
            if (!isset($rooms[(int)$roomId])) {
                if ($wantsJson) {
                    return $this->changeEditJson(403, 'error', 'この部屋を操作する権限がありません。', data: []);
                }
                $this->Flash->error(__('この部屋を操作する権限がありません。'));
                return $this->redirect(['controller' => 'TReservationInfo', 'action' => 'index']);
            }

            $context = $this->changeEditQuery->context((int)$roomId, $date, (int)$login?->get('i_id_user'), (int)($login?->get('i_admin') ?? 0), (int)($login?->get('i_user_level') ?? -1));
            $room    = ['id' => $context->roomId, 'name' => $context->roomName];
            if ($this->request->is('get')) {
                return $wantsJson ? $this->changeEditTableJson($context, $date) : $this->changeEditScreen($room, $rooms, $date, true);
            }
            // ここに来るのは GET 以外。保存するのは POST/PUT だけで、それ以外（DELETE など）は保存せずにそのまま画面を出す
            if ($this->request->is(['post', 'put'])) {
                $saved = $this->saveChangeEditBulk($saveChangeEdit, $data, $context, $date, $wantsJson);
                if ($saved !== null) {
                    return $saved;
                }
            }
            // 保存できなかった（画面）。理由は Flash に出して、同じ画面を出す
            return $this->changeEditScreen($room, $rooms, $date, false);
        } catch (\Throwable $e) {
            return $this->changeEditError($e, $wantsJson);
        }
    }

    /**
     * ログインしていて、職員でも管理者でもなく所属も無い人は使えない（Policy の canChangeEdit）。
     * authorizeReservation の拒否とは文言・status（forbidden）が違うので先に見る。未ログインは authorizeReservation に任せる。
     */
    private function changeEditDeniedForNonMember(bool $wantsJson): ?Response
    {
        if ($this->request->getAttribute('identity') === null || $this->Authorization->can(new TReservationInfo(), 'changeEdit')) {
            return null;
        }
        $this->Authorization->skipAuthorization();
        $reason = '直前編集は職員・管理者・所属グループがある方のみ使用できます。予約変更は通常の予約画面からご利用ください。';
        if ($wantsJson) {
            return $this->changeEditJson(403, 'forbidden', $reason);
        }
        $this->Flash->error($reason);

        return $this->redirect(['controller' => 'TReservationInfo', 'action' => 'index']);
    }

    /**
     * モーダルの枠（GET ?modal=1）。部屋の指定が無ければ、その日に予約がある部屋を選ぶ。
     *
     * @param array<int, string> $rooms 使える部屋
     * @param int|string|null $roomId 指定された部屋（リクエストのまま）
     * @param string|null $date YYYY-MM-DD（形式は changeEdit で確認済み）。無ければ null
     */
    private function changeEditModalShell(array $rooms, $roomId, ?string $date): Response
    {
        if (!$roomId && $date && !empty($rooms)) {
            $roomId = $this->changeEditQuery->defaultRoomId($rooms, $date);
        }
        $room = null;
        if ($roomId && isset($rooms[(int)$roomId])) {
            $name = $this->changeEditQuery->roomName((int)$roomId);
            $room = $name === null ? null : ['id' => (int)$roomId, 'name' => $name];
        }
        if (empty($rooms)) {
            $this->log('changeEdit modal: No rooms available for user ' . $this->request->getAttribute('identity')?->get('i_id_user'), 'warning');
        }

        return $this->changeEditScreen($room, $rooms, $date, true);
    }

    /**
     * 直前編集の画面。$withOwnMeals のときは、本人のその日の予約（個人の直前編集の初期表示）も渡す。
     *
     * @param array{id: int, name: string}|null $room
     * @param array<int, string> $rooms
     * @param string|null $date YYYY-MM-DD（形式は changeEdit で確認済み）。無ければ null で、本人の予約も空にする
     */
    private function changeEditScreen(?array $room, array $rooms, ?string $date, bool $withOwnMeals): Response
    {
        $this->set(compact('room', 'rooms', 'date'));
        if ($withOwnMeals) {
            $loginUid = $this->request->getAttribute('identity')?->get('i_id_user');
            $this->set('individualReservations', $loginUid && $date ? $this->changeEditQuery->ownMeals((int)$loginUid, $date) : []);
        }

        return $this->render('change_edit');
    }

    /** 部屋の表（GET の JSON）。行ごとの allowEdit は ChangeEditQuery が見る人に合わせて決めている。 */
    private function changeEditTableJson(ChangeEditContext $context, string $date): Response
    {
        return $this->changeEditJson(200, 'success', null, [
            'contextRoom'      => ['id' => $context->roomId, 'name' => $context->roomName],
            'date'             => $date,
            'users'            => array_map(static fn(ChangeEditMember $m): array => $m->toJson(), $context->members),
            'userReservations' => $context->reservations,
        ]);
    }

    /**
     * POST の中身（フォーム。空なら JSON の本文）。
     *
     * @return array<array-key, mixed>
     */
    private function changeEditRequestData(): array
    {
        $data = $this->request->getData();
        if (empty($data)) {
            $parsed = json_decode((string)$this->request->getBody(), true);
            if (is_array($parsed)) {
                $data = $parsed;
            }
        }

        return (array)$data;
    }

    /**
     * 個人の直前編集の保存（reservation_type=1）。
     *
     * @param array<array-key, mixed> $data
     * @param array<int, string> $rooms 使える部屋
     */
    private function saveChangeEditIndividual(RegisterIndividualReservationUseCase $registerIndividual, array $data, ?string $date, array $rooms, bool $wantsJson): ?Response
    {
        $postDate = $date ?? ($data['d_reservation_date'] ?? null);
        if (!$postDate) {
            if ($wantsJson) {
                return $this->changeEditJson(422, 'error', '日付が指定されていません。');
            }
            $this->Flash->error('日付が指定されていません。');
            return $this->redirect(['controller' => 'TReservationInfo', 'action' => 'index']);
        }

        $login = $this->request->getAttribute('identity');
        try {
            $result = $registerIndividual->execute(new RegisterIndividualReservationInput(
                reservationDate: (string)$postDate,
                data: $data,
                rooms: $rooms,
                userId: (int)$login?->get('i_id_user'),
                userName: (string)($login?->get('c_user_name') ?? ''),
                // 直前編集は14日以内を扱う。過ぎた日（実績）は書き換えさせない（予約の新規登録 add とは基準が違うので、ここで決める）
                dateError: $this->datePolicy->isPastDate((string)$postDate) ? '過去日の予約は変更できません。' : null,
            ))->toArray($this->reservationIndexUrl());
            $this->recordReservationAudit('reservation_change_edit', (string)$postDate, ['date' => (string)$postDate, 'type' => 'individual'], true);
            $message = '直前予約（個人）を更新しました。';
            if ($wantsJson) {
                // 個人の保存だけ、data の後ろに date を付ける（以前のまま）
                return $this->jsonResponse(self::changeEditBody('success', $message, $result) + ['date' => $postDate]);
            }
            $this->Flash->success($message);
            return $this->redirect(['controller' => 'TReservationInfo', 'action' => 'index']);
        } catch (\App\Domain\Exception\DomainException $e) {
            $this->log('直前編集（個人）エラー: ' . $e->getMessage(), 'warning');
            $this->recordReservationAudit('reservation_change_edit', (string)$postDate, ['date' => (string)$postDate, 'type' => 'individual'], false, $e->getMessage());
            if ($wantsJson) {
                return $this->changeEditJson($e->getStatusCode(), 'error', $e->getMessage());
            }
            $this->Flash->error($e->getMessage());
            return $this->redirect(['controller' => 'TReservationInfo', 'action' => 'index']);
        } catch (\Throwable $e) {
            $this->log('直前編集（個人）エラー: ' . $e->getMessage(), 'error');
            $this->recordReservationAudit('reservation_change_edit', (string)$postDate, ['date' => (string)$postDate, 'type' => 'individual'], false, '予期しないエラーで保存できませんでした');
            if ($wantsJson) {
                return $this->changeEditJson(500, 'error', '予約の更新中にエラーが発生しました。');
            }
            $this->Flash->error('予約の更新中にエラーが発生しました。');
            return $this->redirect(['controller' => 'TReservationInfo', 'action' => 'index']);
        }
    }

    /**
     * 部屋の表のまとめて保存。画面で保存できなかったときは Flash に理由を出して null を返す（呼び出し側が画面を出す）。
     *
     * @param array<array-key, mixed> $data
     */
    private function saveChangeEditBulk(SaveChangeEditUseCase $saveChangeEdit, array $data, ChangeEditContext $context, string $date, bool $wantsJson): ?Response
    {
        $login = $this->request->getAttribute('identity');
        try {
            $output = $saveChangeEdit->execute(new SaveChangeEditInput(
                usersData: (isset($data['users']) && is_array($data['users'])) ? $data['users'] : [],
                memberIds: $context->memberIds(),
                date: $date,
                roomId: $context->roomId,
                loginUserId: (int)$login?->get('i_id_user'),
                loginUserName: (string)($login?->get('c_user_name') ?? 'system'),
                loginAdmin: (int)($login?->get('i_admin') ?? 0),
                loginLevel: (int)($login?->get('i_user_level') ?? -1),
            ));
            $this->recordReservationAudit('reservation_change_edit', 'room:' . $context->roomId, [
                'date' => $date, 'type' => 'room', 'updated_count' => count($output->updated), 'created_count' => count($output->created), 'skipped_count' => count($output->skipped),
            ], true);
            $message = '直前予約を更新しました。';
            if ($wantsJson) {
                return $this->changeEditJson(200, 'success', $message, ['updated' => $output->updated, 'created' => $output->created, 'skipped' => $output->skipped]);
            }
            $this->Flash->success(__($message));
            return $this->redirect(['controller' => 'TReservationInfo', 'action' => 'index']);
        } catch (\App\Domain\Exception\DomainException $e) {
            [$httpStatus, $status, $message] = self::changeEditFailure($e);
            $this->log('直前編集 変更拒否: ' . $e->getMessage(), $httpStatus >= 500 ? 'error' : 'warning');
            $this->recordReservationAudit('reservation_change_edit', 'room:' . $context->roomId, ['date' => $date, 'type' => 'room'], false, $message);
            if ($wantsJson) {
                return $this->changeEditJson($httpStatus, $status, $message, data: []);
            }
            $this->Flash->error(__($message));
        } catch (\Throwable $e) {
            $this->log('直前編集エラー: ' . $e->getMessage(), 'error');
            $this->recordReservationAudit('reservation_change_edit', 'room:' . $context->roomId, ['date' => $date, 'type' => 'room'], false, '予期しないエラーで保存できませんでした');
            if ($wantsJson) {
                return $this->changeEditJson(500, 'error', '直前予約の更新中にエラーが発生しました。', data: []);
            }
            $this->Flash->error(__('直前予約の更新中にエラーが発生しました。'));
        }

        return null;
    }

    /**
     * 直前編集で拾えなかった失敗。JSON は 400（入力の誤り）・404（部屋が無い）・500 で固定の文言を返す。
     * 画面は、入力の誤りを 400、部屋が無いを 404 に変えて投げ直す（それ以外はそのまま）。
     *
     * @throws BadRequestException
     * @throws NotFoundException
     * @throws \Throwable
     */
    private function changeEditError(\Throwable $e, bool $wantsJson): Response
    {
        $this->log('changeEdit error: ' . $e->getMessage(), 'error');
        if ($wantsJson) {
            $httpStatus = match (true) {
                $e instanceof \InvalidArgumentException => 400,
                $e instanceof NotFoundException, $e instanceof DomainNotFoundException => 404,
                default => 500,
            };
            return $this->changeEditJson($httpStatus, 'error', '直前予約の取得中にエラーが発生しました。', data: []);
        }
        // 部屋・日付が無いなどの入力の誤りは、画面でも 400 で返す（JSON と同じ）
        if ($e instanceof \InvalidArgumentException) {
            throw new BadRequestException($e->getMessage(), null, $e);
        }
        if ($e instanceof DomainNotFoundException) {
            throw new NotFoundException($e->getMessage(), null, $e);
        }

        throw $e;
    }

    /**
     * 直前編集の JSON 応答。本文は changeEditBody。
     *
     * @param int $httpStatus HTTP の状態コード
     * @param string $status 本文の status（success・error・forbidden・conflict・approved）
     * @param array<array-key, mixed>|null $data null なら data キーを付けない。空でも付けるときは data: [] と書く
     */
    private function changeEditJson(int $httpStatus, string $status, ?string $message, ?array $data = null): Response
    {
        return $this->jsonResponse(self::changeEditBody($status, $message, $data), $httpStatus);
    }

    /**
     * 直前編集の JSON 本文（ok・status・message、data があれば data）。ok は status が success のときだけ true。
     *
     * @param array<array-key, mixed>|null $data null なら data キーを付けない
     * @return array<string, bool|string|array<array-key, mixed>|null>
     */
    private static function changeEditBody(string $status, ?string $message, ?array $data = null): array
    {
        $body = ['ok' => $status === 'success', 'status' => $status, 'message' => $message];
        if ($data !== null) {
            $body['data'] = $data;
        }

        return $body;
    }

    /**
     * 直前編集の保存の失敗を、応答（HTTP の状態・status・利用者に出す文言）にする。
     *
     * @return array{0: int, 1: string, 2: string}
     */
    private static function changeEditFailure(\App\Domain\Exception\DomainException $e): array
    {
        return match (true) {
            $e instanceof \App\Domain\Exception\ApprovedReservationChangeException => [409, 'approved', $e->getMessage()],
            $e instanceof \App\Domain\Exception\ConflictException => [409, 'conflict', $e->getMessage()],
            // 新しい行の検証ルール。昼食と弁当なら理由を返し、それ以外はシステムの失敗として扱う
            $e instanceof \App\Domain\Exception\ReservationValidationException => $e->lunchBentoMessage !== null
                ? [409, 'conflict', $e->lunchBentoMessage]
                : [500, 'error', '直前予約の更新中にエラーが発生しました。'],
            $e instanceof \App\Domain\Exception\PersistenceException => [500, 'error', '直前予約の更新中にエラーが発生しました。'],
            // 入力・権限の拒否（過去日など）。理由をそのまま返す
            default => [$e->getStatusCode(), 'error', $e->getMessage()],
        };
    }
}
