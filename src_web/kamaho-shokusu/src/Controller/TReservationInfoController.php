<?php
declare(strict_types=1);

namespace App\Controller;

use Cake\Http\ServerRequest;
use App\Application\Query\ReservationReadQueryInterface;
use App\Application\Query\RoomQueryInterface;
use App\Application\Query\CalendarQueryInterface;
use App\Application\Query\CalendarEventBuilder;
use App\Application\Query\ChangeEditQueryInterface;
use App\Application\Query\Dto\ChangeEditContext;
use App\Application\Query\Dto\ChangeEditMember;
use App\Domain\Service\DatePeriodRule;
use App\Application\Query\DayViewQueryInterface;
use App\Application\Query\RoomMealDetailQueryInterface;
use App\Domain\Exception\NotFoundException as DomainNotFoundException;
use App\Application\UseCase\RegisterGroupReservation\RegisterGroupReservationInput;
use App\Application\UseCase\RegisterGroupReservation\RegisterGroupReservationUseCase;
use App\Application\UseCase\RegisterIndividualReservation\RegisterIndividualReservationInput;
use App\Application\UseCase\RegisterIndividualReservation\RegisterIndividualReservationUseCase;
use App\Application\UseCase\SaveChangeEdit\SaveChangeEditInput;
use App\Application\UseCase\SaveChangeEdit\SaveChangeEditUseCase;
use App\Domain\ValueObject\UserRole;
use App\Infrastructure\Persistence\Entity\TReservationInfo;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Exception\UnauthorizedException;
use Cake\Http\Response;
use Cake\I18n\Date;
use App\Service\ReservationAddService;
use Cake\Routing\Router;

/**
 * TReservationInfo コントローラー（コア予約CRUD・カレンダー表示担当）。
 *
 * 以下の責務を持つ:
 * - カレンダーIndex・イベント取得
 * - 日別ビュー・部屋詳細
 * - 個人/集団予約の新規登録 (add)
 * - 直前編集 (changeEdit)
 * - 部屋別利用者取得・個人予約取得・重複チェック
 *
 * 一括・コピー・レポート・実食管理は各専用コントローラーへ分離済み。
 */
class TReservationInfoController extends ReservationBaseController
{
    private ReservationAddService $addService;

    public function __construct(
        ServerRequest $request,
        CalendarQueryInterface $calendarQuery,
        RoomQueryInterface $roomQuery,
        ReservationReadQueryInterface $reservationRead,
        private readonly ChangeEditQueryInterface $changeEditQuery,
    ) {
        parent::__construct($request, $calendarQuery, $roomQuery, $reservationRead);
    }

    /**
     * initialize メソッド
     */
    public function initialize(): void
    {
        parent::initialize();

        $this->addService        = new ReservationAddService();

        $this->FormProtection->setConfig('unlockedActions', [
            'add',
            'checkDuplicateReservation',
            'changeEdit',
            'view',
        ]);
    }


    /**
     * インデックスメソッド
     *
     * @return Response|null|void ビューをレンダリングする
     */
    public function index(): ?Response
    {
        $this->authorizeReservation('index');

        $authUser = $this->Authentication->getIdentity();
        if (!$authUser) {
            throw new UnauthorizedException('ログインが必要です。');
        }
        $userId   = $authUser->get('i_id_user');
        $user     = $this->MUserInfo->get($userId);
        $today    = Date::today();

        // 有効な所属部屋（退出済み・削除済みを除く）。既定の部屋もこの中から選ぶ
        $userRoomIds = array_keys($this->calendarQuery->getRoomsForUser($this->roomQuery->getUserRoomIds((int)$userId), false));
        $userRoomId  = $userRoomIds !== [] ? (int)$userRoomIds[0] : null;

        $isAdmin       = UserRole::isAdmin((int)($user->i_admin ?? 0));
        $isBlockLeader = UserRole::isBlockLeader((int)($user->i_admin ?? 0));
        $isOfficeUser  = $this->roomQuery->isOfficeUser((int)$userId);
        // 管理者・システム管理者も所属部屋のみ（#821）。全部屋の集計を見られるのは事務所ユーザーだけ。
        $canViewAllRooms = $isOfficeUser;
        $rooms         = $this->calendarQuery->getRoomsForUser($userRoomIds, false, $isOfficeUser);
        $authorizedRooms = $rooms;

        // カレンダーの予約ポップアップで選べる部屋。管理者も所属外の部屋には予約できないため（#821）、
        // 役割を問わず「所属していて削除されていない部屋」に限る
        $pickerRooms = $this->calendarQuery->getRoomsForUser($userRoomIds, false);

        // 選べない部屋（所属外など）を指定されたときは、指定なしと同じ既定の部屋にする（選択欄の表示と集計を揃える）
        $calRoomIdQuery = $this->request->getQuery('cal_room_id');
        $requestedCalRoomId = ($calRoomIdQuery !== null && $calRoomIdQuery !== '') ? (int)$calRoomIdQuery : null;
        $calRoomId = null;
        if ($requestedCalRoomId !== null && isset($rooms[$requestedCalRoomId])) {
            $calRoomId = $requestedCalRoomId;
        } elseif (!$canViewAllRooms && $userRoomId !== null && isset($rooms[(int)$userRoomId])) {
            $calRoomId = (int)$userRoomId;
        }
        // 初期表示は前月〜翌2ヶ月の範囲に絞る（FullCalendarの表示範囲に合わせる）
        // サービス側が endDate を排他的（<）に扱うため、viewEnd は翌々月末の翌日（月初）を指定する
        $firstOfMonth = new Date($today->format('Y-m') . '-01');
        $viewStart    = $firstOfMonth->subMonths(1)->format('Y-m-d');
        $viewEnd      = $firstOfMonth->addMonths(3)->format('Y-m-d');

        $calRoomFilter = $calRoomId !== null
            ? [$calRoomId]
            : $this->countableRoomIds($canViewAllRooms, $userRoomIds);

        $mealDataArray        = $this->calendarQuery->getMealCountsByDate($calRoomFilter, $viewStart, $viewEnd);
        $myReservationDetails = $this->calendarQuery->getMyReservationDays((int)$userId, $viewStart, $viewEnd);
        $myReservationDates   = CalendarEventBuilder::reservedDates($myReservationDetails);

        // 予約に登場する部屋の名前
        $reservationRoomIds = [];
        foreach ($myReservationDetails as $day) {
            foreach ([$day->breakfastRoom, $day->lunchRoom, $day->dinnerRoom, $day->bentoRoom] as $roomId) {
                if (!empty($roomId)) {
                    $reservationRoomIds[(int)$roomId] = true;
                }
            }
        }
        $reservationRoomNames = $this->calendarQuery->getRoomNames(array_keys($reservationRoomIds));

        $this->set(compact(
            'mealDataArray',
            'myReservationDates',
            'myReservationDetails',
            'reservationRoomNames',
            'user',
            'userRoomId',
            'rooms',
            'authorizedRooms',
            'pickerRooms',
            'today',
            'isAdmin',
            'canViewAllRooms',
            'calRoomId'
        ));
        return null;
    }

    /**
     * イベントメソッド - FullCalendarで使用するイベントデータを提供する
     *
     * @return Response|null|void JSONレスポンスを返す
     */
    public function events(): ?Response
    {
        if ($denied = $this->authorizeReservation('events', [], true)) {
            return $denied;
        }

        $this->request->allowMethod(['get']);

        $start = $this->queryString('start');
        $end   = $this->queryString('end');

        if ($start === '' || $end === '') {
            return $this->apiResponseService->error($this->response, 'Invalid date range', 400);
        }

        try {
            $startDate = new Date($start);
            $endDate   = new Date($end);
        } catch (\Throwable) {
            return $this->apiResponseService->error($this->response, 'Invalid date range', 400);
        }

        if ($startDate->diffInDays($endDate, false) < 0 || $startDate->diffInDays($endDate, false) > 366) {
            return $this->apiResponseService->error($this->response, 'Invalid date range', 400);
        }

        // 合計は有効な所属部屋のみ（管理者も同じ。#821）。全部屋の合計は事務所ユーザーだけ
        $userId  = (int)$this->Authentication->getIdentity()?->get('i_id_user');
        $roomIds = $this->countableRoomIds(
            $this->roomQuery->isOfficeUser($userId),
            array_keys($this->calendarQuery->getRoomsForUser($this->roomQuery->getUserRoomIds($userId), false))
        );

        $events = CalendarEventBuilder::totalEvents(
            $this->calendarQuery->getTotalCountsByDate($startDate->format('Y-m-d'), $endDate->format('Y-m-d'), $roomIds)
        );

        return $this->apiResponseService->success($this->response, ['events' => $events]);
    }

    /**
     * 食数の集計対象の部屋。事務所ユーザーは有効な全部屋、それ以外は有効な所属部屋（どちらも削除済みを除く。#821）。
     * 空のときは該当なしの ID を返し、全部屋の集計に落ちないようにする。
     *
     * @param array<int> $validUserRoomIds 有効な所属部屋
     * @return array<int>
     */
    private function countableRoomIds(bool $isOfficeUser, array $validUserRoomIds): array
    {
        $roomIds = $isOfficeUser
            ? array_keys($this->calendarQuery->getRoomsForUser([], true))
            : $validUserRoomIds;

        return $roomIds ?: [0];
    }

    /**
     * カレンダー表示用イベント（食数 + 自分の予約 + 未予約）
     *
     * @return Response JSONレスポンス
     */
    public function calendarEvents(): Response
    {
        $resource = new \App\Infrastructure\Persistence\Entity\TReservationInfo();
        $this->Authorization->authorize($resource, 'calendarEvents');
        $this->request->allowMethod(['get']);

        $authUser = $this->Authentication->getIdentity();
        if (!$authUser) {
            throw new UnauthorizedException('ログインが必要です。');
        }

        $userId = (int)$authUser->get('i_id_user');
        $user   = $this->MUserInfo->get($userId);

        $start = $this->queryString('start');
        $end   = $this->queryString('end');

        try {
            $startDate = new Date($start);
            $endDate   = new Date($end);
        } catch (\Throwable) {
            return $this->apiResponseService->error($this->response, 'Invalid date range', 400);
        }

        $diffDays = $startDate->diffInDays($endDate, false);
        if ($diffDays < 0) {
            return $this->apiResponseService->error($this->response, 'Invalid date range', 400);
        }

        if ($diffDays > 366) {
            return $this->apiResponseService->error($this->response, 'Date range too large', 400);
        }

        $canViewAllRooms = $this->roomQuery->isOfficeUser((int)$userId);

        // 集計は有効な所属部屋（削除済みの部屋を除く）だけ。index と同じ基準
        $userRoomIds = array_keys($this->calendarQuery->getRoomsForUser($this->roomQuery->getUserRoomIds((int)$userId), false));

        $events = CalendarEventBuilder::calendarEvents(
            $this->calendarQuery->getMealCountsByDate($this->countableRoomIds($canViewAllRooms, $userRoomIds), $startDate->format('Y-m-d'), $endDate->format('Y-m-d')),
            $this->calendarQuery->getMyReservationDays((int)$userId, $startDate->format('Y-m-d'), $endDate->format('Y-m-d')),
            $startDate,
            $endDate
        );

        return $this->apiResponseService->success($this->response, ['events' => $events]);
    }


    /**
     * ビューメソッド
     *
     * @return Response|null|void ビューをレンダリングする
     */
    public function view(DayViewQueryInterface $dayView): ?Response
    {
        $this->authorizeReservation('view');

        $date       = $this->request->getParam('date')
            ?? $this->request->getParam('pass.0')
            ?? $this->request->getQuery('date');
        // 暦にない日付・配列は入力の誤り（以前は 500、または翌月に繰り越して 200）
        if ($date !== null && $date !== '' && (!is_string($date) || !DatePeriodRule::isDate($date))) {
            throw new BadRequestException('日付が正しくありません。');
        }
        $roomIdRaw  = $this->request->getData('room_id') ?? $this->request->getQuery('room_id');
        $day = $dayView->getDayView(
            (int)($this->request->getAttribute('identity')?->get('i_id_user') ?? 0),
            $date,
            $roomIdRaw !== null ? (int)$roomIdRaw : null
        );

        $this->set(compact('day'));
        return null;
    }

    /**
     * 部屋詳細メソッド
     *
     * @param int $roomId 部屋ID
     * @param string $date 日付
     * @param int $mealType 食事タイプ
     * @return Response|null|void ビューをレンダリングする
     */
    public function roomDetails(RoomMealDetailQueryInterface $roomMealDetail, $roomId, $date, $mealType): ?Response
    {
        $this->authorizeReservation('roomDetails', ['i_id_room' => (int)$roomId]);

        $this->log(sprintf('roomId: %d, date: %s, mealType: %d', (int)$roomId, preg_replace('/[\r\n\t]/', '', (string)$date), (int)$mealType), 'debug');

        if (empty($roomId) || empty($date) || empty($mealType)) {
            throw new BadRequestException('部屋ID、日付、または食事タイプが指定されていません。');
        }

        // 2026-02-30 のような暦にない日付も弾く（以前は DB エラーで 500）
        if (!DatePeriodRule::isDate((string)$date)) {
            throw new BadRequestException('日付の形式が正しくありません。');
        }

        if (!is_numeric($mealType)) {
            throw new BadRequestException('食事タイプは整数である必要があります。');
        }

        try {
            $detail = $roomMealDetail->getRoomMealDetail((int)$roomId, (string)$date, (int)$mealType);
        } catch (DomainNotFoundException $e) {
            throw new NotFoundException(__('部屋が見つかりません。'));
        } catch (\InvalidArgumentException $e) {
            // 形は合っていても存在しない日付（例：2026-13-45）は、入力の誤りとして返す
            throw new BadRequestException($e->getMessage());
        }

        $this->set([
            'detail'   => $detail,
            'date'     => $date,
            'mealType' => $mealType,
        ]);
        return null;
    }

    /**
     * 所属部屋の利用者一覧取得API。
     *
     * @param int|null $roomId
     * @return Response|null
     */
    public function getUsersByRoom($roomId = null): ?Response
    {
        if ($denied = $this->authorizeReservation('getUsersByRoom', ['i_id_room' => (int)$roomId], true)) {
            return $denied;
        }

        $this->request->allowMethod(['get']);

        if (!$roomId) {
            return $this->jsonErrorResponse(__('部屋IDが指定されていません。'));
        }

        try {
            $date = $this->queryDate('date');
        } catch (BadRequestException $e) {
            return $this->jsonErrorResponse($e->getMessage());
        }
        $usersByRoom = array_map(
            static fn(\App\Application\Query\Dto\RoomUserMeals $u) => $u->toArray(),
            $this->reservationRead->getUsersByRoom((int)$roomId, $date)
        );

        return $this->apiResponseService->success($this->response, ['usersByRoom' => $usersByRoom]);
    }

    /**
     * 個人予約情報取得API。
     *
     * @return Response
     */
    public function getPersonalReservation(): ?Response
    {
        if ($denied = $this->authorizeReservation('getPersonalReservation', [], true)) {
            return $denied;
        }

        $this->autoRender = false;
        $this->viewBuilder()->disableAutoLayout();
        $this->request->allowMethod(['get']);

        try {
            $date = $this->queryDate('date');
        } catch (BadRequestException $e) {
            return $this->apiResponseService->error($this->response, $e->getMessage(), 400);
        }
        if ($date === null) {
            return $this->apiResponseService->error($this->response, '日付が指定されていません。', 400);
        }

        $user = $this->Authentication->getIdentity();
        if (!$user) {
            return $this->apiResponseService->error($this->response, 'ログイン情報がありません。', 403);
        }
        $userId   = $user->get('i_id_user');
        $userName = $user->get('c_user_name');

        $output = $this->reservationRead->getPersonalReservation((int)$userId, (string)$userName, $date)->toArray();

        return $this->apiResponseService->success($this->response, $output);
    }

    /**
     * 重複予約チェックAPI。
     *
     * @return Response|null
     */
    public function checkDuplicateReservation(): ?Response
    {
        $roomId = (int)($this->request->getData('i_id_room') ?? 0);
        if ($denied = $this->authorizeReservation('checkDuplicateReservation', ['i_id_room' => $roomId], true)) {
            return $denied;
        }

        $this->request->allowMethod(['post']);

        $data = $this->request->getData();

        if (empty($data['d_reservation_date']) || empty($data['i_id_room']) || empty($data['reservation_type'])) {
            return $this->apiResponseService->error(
                $this->response,
                '必要なデータが不足しています。',
                400,
                ['isDuplicate' => false]
            );
        }

        $isDuplicate = $this->reservationRead->hasDuplicateReservation(
            (string)$data['d_reservation_date'],
            (int)$data['i_id_room'],
            (int)$data['reservation_type']
        );

        if ($isDuplicate) {
            $editUrl = Router::url([
                'controller' => 'TReservationInfo',
                'action'     => 'edit',
                'roomId'     => $data['i_id_room'],
                'date'       => $data['d_reservation_date'],
                'mealType'   => $data['reservation_type'],
            ]);

            return $this->apiResponseService->success($this->response, [
                'isDuplicate' => true,
                'editUrl'     => $editUrl,
            ]);
        }

        return $this->apiResponseService->success($this->response, ['isDuplicate' => false]);
    }

    /**
     * 予約登録メソッド（個人予約・集団予約）。
     */
    public function add(RegisterIndividualReservationUseCase $registerIndividual, RegisterGroupReservationUseCase $registerGroup)
    {
        $this->authorizeReservation('add');

        $user       = $this->request->getAttribute('identity');
        $isModal    = ($this->request->getQuery('modal') === '1');
        $addService = $this->addService;

        if ($isModal) {
            $this->viewBuilder()->disableAutoLayout();
            $this->response = $this->response
                ->withHeader('X-Frame-Options', 'SAMEORIGIN')
                ->withHeader('Content-Security-Policy', "frame-ancestors 'self'");
        }

        $date = $this->queryDate('date') ?? Date::today()->format('Y-m-d');

        if (!$user) {
            $this->set('errorMessage', __('ログインが必要です。'));
            $this->set('date', $date);
            $this->set('rooms', []);
            $this->set('roomList', []);
            $this->set('userLevel', null);
            $this->set('tReservationInfo', $this->TReservationInfo->newEmptyEntity());
            return null;
        }

        $userLevel = $user->i_user_level;
        $userId    = $user->get('i_id_user');
        $rooms     = $this->roomQuery->getAccessibleRooms((int)$userId);

        if ($this->request->is('get')) {
            $validation = $addService->validateDate((string)$date, $this->datePolicy);
            $roomList   = $this->roomQuery->getAllActiveRooms();

            $tReservationInfo = $this->TReservationInfo->newEmptyEntity();

            $this->set(compact('rooms', 'tReservationInfo', 'date', 'roomList', 'userLevel'));

            if ($validation !== true) {
                $this->set('errorMessage', __($validation));
            } elseif (!$roomList) {
                $this->set('errorMessage', __('部屋が見つかりません。'));
            }

            return null;
        }

        $validation = $addService->validateDate((string)$date, $this->datePolicy);
        if ($validation !== true) {
            $msg = __((string)$validation);
            if ($this->request->is('ajax')) {
                return $this->jsonErrorResponse($msg, 422);
            }
            $this->Flash->error($msg);
            return $this->redirect(['action' => 'index', '?' => ['date' => $date]]);
        }

        $roomList = $this->roomQuery->getAllActiveRooms();

        if (!$roomList) {
            if ($this->request->is('ajax')) {
                return $this->jsonErrorResponse(__('部屋が見つかりません。'), 404);
            }
            $this->Flash->error(__('部屋が見つかりません。'));
            return $this->redirect(['action' => 'index', '?' => ['date' => $date]]);
        }

        $tReservationInfo = $this->TReservationInfo->newEmptyEntity();

        if ($this->request->is('post')) {
            $data = $addService->ensureReservationDate($this->request->getData(), (string)$date);

            $conflict = $addService->validateLunchBento($data);
            if ($conflict) {
                if ($this->request->is('ajax')) {
                    return $this->jsonErrorResponse(__($conflict), 409);
                }
                $this->Flash->error(__($conflict));
                return $this->redirect(['action' => 'index', '?' => ['date' => $data['d_reservation_date']]]);
            }

            $reservationType = $data['reservation_type'] ?? '1';
            $auditSuccess = 0;
            try {
                $dateValidation = $this->datePolicy->validateReservationDate((string)$data['d_reservation_date']);
                $dateError      = $dateValidation === true ? null : (string)$dateValidation;
                $result = ((string)$reservationType === '1')
                    ? $registerIndividual->execute(new RegisterIndividualReservationInput(
                        reservationDate: $data['d_reservation_date'],
                        data: $data,
                        rooms: $rooms,
                        userId: (int)$userId,
                        userName: (string)$user->get('c_user_name'),
                        dateError: $dateError,
                    ))->toArray($this->reservationIndexUrl())
                    : $registerGroup->execute(new RegisterGroupReservationInput(
                        reservationDate: $data['d_reservation_date'],
                        data: $data,
                        rooms: $rooms,
                        creatorName: (string)$user->get('c_user_name'),
                        dateError: $dateError,
                        loginUserId: (int)$userId,
                        isAdmin: UserRole::isAdmin((int)$user->get('i_admin')),
                        loginUserLevel: (int)$user->get('i_user_level'),
                        isBlockLeader: UserRole::isBlockLeader((int)$user->get('i_admin')),
                    ))->toArray($this->reservationIndexUrl());
                $auditSuccess = 1;
                $resultResponse = $this->jsonSuccessResponse($result['message'], $result['data'] ?? [], $result['redirect'] ?? null);
            } catch (\App\Domain\Exception\DomainException $e) {
                $resultResponse = $this->jsonErrorResponse($e->getMessage(), $e->getStatusCode());
            } catch (\Throwable $e) {
                $this->log('予約登録エラー: ' . $e->getMessage(), 'error');
                $resultResponse = $this->jsonErrorResponse('内部エラーが発生しました。', 500);
            }

            \App\Service\AuditLogService::record(
                'reservation',
                (string)$reservationType === '1' ? 'reservation_individual_save' : 'reservation_group_save',
                (string)$user->get('c_user_name'),
                (int)$userId,
                't_reservation_info',
                $data['d_reservation_date'] ?? null,
                ['date' => $data['d_reservation_date'] ?? null],
                $this->getClientIp(),
                $auditSuccess,
                (string)($user->get('c_login_account') ?? '')
            );

            // ★ ここからが重要：非AJAXでは「常にサーバ側で配列ルート→redirect()」に集約
            if ($this->request->is('ajax')) {
                return $resultResponse instanceof Response ? $resultResponse : $this->response;
            }

            $defaultRedirect = ['action' => 'index', '?' => ['date' => $data['d_reservation_date']]];

            if ($resultResponse instanceof Response) {
                $ctype = $resultResponse->getType() ?? '';
                if (stripos($ctype, 'application/json') !== false) {
                    $body = (string)$resultResponse->getBody();
                    $json = null;
                    try {
                        $json = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
                    } catch (\Throwable $e) {
                        $this->log('Failed to decode add action JSON response: ' . $e->getMessage(), 'warning');
                    }

                    if (is_array($json)) {
                        if (($json['status'] ?? '') === 'success') {
                            $this->Flash->success($json['message'] ?? __('登録しました。'));
                            if (!empty($json['redirect']) && is_array($json['redirect'])) {
                                return $this->redirect($json['redirect']);
                            }
                            return $this->redirect($defaultRedirect);
                        }
                        $this->Flash->error($json['message'] ?? __('登録に失敗しました。'));
                        return $this->redirect($defaultRedirect);
                    }
                    $this->log('JSON decode failed for add result response.', 'warning');
                }

                if ($resultResponse->getStatusCode() >= 300 && $resultResponse->getStatusCode() < 400) {
                    return $resultResponse;
                }
            }

            $this->Flash->success(__('登録しました。'));
            return $this->redirect($defaultRedirect);
        }

        $this->set(compact('rooms', 'tReservationInfo', 'date', 'roomList', 'userLevel'));
    }

    /**
     * 直前編集（モーダル用API/画面）。どの流れか（モーダルの枠・個人の保存・表の JSON・画面・まとめて保存）を決めるだけ。
     *
     * @param int|null $roomId
     * @param string|null $date YYYY-MM-DD
     * @param int|null $mealType 1=朝,2=昼,3=夜,4=弁当
     * @throws BadRequestException 画面で、部屋・日付が無い・日付が正しくない
     * @throws NotFoundException 画面で、部屋が無い
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
                    return $this->changeEditJson(403, 'error', 'この部屋を操作する権限がありません。', []);
                }
                $this->Flash->error(__('この部屋を操作する権限がありません。'));
                return $this->redirect(['action' => 'index']);
            }

            $context = $this->changeEditQuery->context((int)$roomId, $date, (int)$login?->get('i_id_user'), (int)($login?->get('i_admin') ?? 0), (int)($login?->get('i_user_level') ?? -1));
            $room    = ['id' => $context->roomId, 'name' => $context->roomName];
            if ($this->request->is('get')) {
                return $wantsJson ? $this->changeEditTableJson($context, $date) : $this->changeEditScreen($room, $rooms, $date, true);
            }
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

        return $this->redirect(['action' => 'index']);
    }

    /**
     * モーダルの枠（GET ?modal=1）。部屋の指定が無ければ、その日に予約がある部屋を選ぶ。
     *
     * @param array<int, string> $rooms 使える部屋
     * @param int|string|null $roomId 指定された部屋（リクエストのまま）
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
            return $this->redirect(['action' => 'index']);
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
            $message = '直前予約（個人）を更新しました。';
            if ($wantsJson) {
                return $this->changeEditJson(200, 'success', $message, $result, ['date' => $postDate]);
            }
            $this->Flash->success($message);
            return $this->redirect(['action' => 'index']);
        } catch (\App\Domain\Exception\DomainException $e) {
            $this->log('直前編集（個人）エラー: ' . $e->getMessage(), 'warning');
            if ($wantsJson) {
                return $this->changeEditJson($e->getStatusCode(), 'error', $e->getMessage());
            }
            $this->Flash->error($e->getMessage());
            return $this->redirect(['action' => 'index']);
        } catch (\Throwable $e) {
            $this->log('直前編集（個人）エラー: ' . $e->getMessage(), 'error');
            if ($wantsJson) {
                return $this->changeEditJson(500, 'error', '予約の更新中にエラーが発生しました。');
            }
            $this->Flash->error('予約の更新中にエラーが発生しました。');
            return $this->redirect(['action' => 'index']);
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
            $message = '直前予約を更新しました。';
            if ($wantsJson) {
                return $this->changeEditJson(200, 'success', $message, ['updated' => $output->updated, 'created' => $output->created, 'skipped' => $output->skipped]);
            }
            $this->Flash->success(__($message));
            return $this->redirect(['action' => 'index']);
        } catch (\App\Domain\Exception\DomainException $e) {
            [$status, $state, $message] = self::changeEditFailure($e);
            $this->log('直前編集 変更拒否: ' . $e->getMessage(), $status >= 500 ? 'error' : 'warning');
            if ($wantsJson) {
                return $this->changeEditJson($status, $state, $message, []);
            }
            $this->Flash->error(__($message));
        } catch (\Throwable $e) {
            $this->log('直前編集エラー: ' . $e->getMessage(), 'error');
            if ($wantsJson) {
                return $this->changeEditJson(500, 'error', '直前予約の更新中にエラーが発生しました。', []);
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
            $status = match (true) {
                $e instanceof \InvalidArgumentException => 400,
                $e instanceof NotFoundException, $e instanceof DomainNotFoundException => 404,
                default => 500,
            };
            return $this->changeEditJson($status, 'error', '直前予約の取得中にエラーが発生しました。', []);
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
     * 直前編集の JSON 応答（ok・status・message、data があれば data、その後に $extra）。ok は status が success のときだけ true。
     *
     * @param array<array-key, mixed>|null $data null なら data キーを付けない
     * @param array<string, mixed> $extra data の後ろに付けるキー
     */
    private function changeEditJson(int $status, string $state, ?string $message, ?array $data = null, array $extra = []): Response
    {
        $body = ['ok' => $state === 'success', 'status' => $state, 'message' => $message];
        if ($data !== null) {
            $body['data'] = $data;
        }

        return $this->response->withStatus($status)->withType('application/json')
            ->withStringBody(json_encode($body + $extra, JSON_UNESCAPED_UNICODE));
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

    private function validateReservationDate(?string $reservationDate): string|bool
    {
        return $this->datePolicy->validateReservationDate($reservationDate);
    }

    /**
     * @param array<int, array<string, mixed>> $duplicates
     */
    private function formatDuplicateMessage(array $duplicates): string
    {
        if (empty($duplicates)) {
            return '重複した予約はありません。';
        }

        $parts = [];
        foreach ($duplicates as $row) {
            $userName = (string)($row['user_name'] ?? '不明ユーザー');
            $mealType = (string)($row['meal_type'] ?? '不明');
            $roomName = (string)($row['room_name'] ?? '不明な部屋');
            $parts[]  = sprintf('%s（%s・%s）', $userName, $mealType, $roomName);
        }

        return '既に予約済みのためスキップ: ' . implode('、', $parts);
    }

    /** 予約の書き込みのあとに戻る一覧の URL。 */
    private function reservationIndexUrl(): string
    {
        return (string)($this->request->getAttribute('webroot') ?? '') . 'TReservationInfo/';
    }
}
