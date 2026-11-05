<?php
declare(strict_types=1);

namespace App\Controller;

use App\Application\Query\CalendarEventBuilder;
use App\Domain\Service\DatePeriodRule;
use App\Application\Query\DayViewQueryInterface;
use App\Application\Query\RoomMealDetailQueryInterface;
use App\Domain\Exception\NotFoundException as DomainNotFoundException;
use App\Domain\ValueObject\UserRole;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Exception\UnauthorizedException;
use Cake\Http\Response;
use Cake\I18n\Date;
use Cake\Routing\Router;

/**
 * TReservationInfo コントローラー（予約カレンダーの表示・読み取り API）。
 *
 * 以下の責務を持つ:
 * - カレンダーIndex・イベント取得
 * - 日別ビュー・部屋詳細
 * - 部屋別利用者取得・個人予約取得・重複チェック
 *
 * 新規登録（add）は ReservationAddController、直前編集（changeEdit）は ReservationChangeEditController。
 * 一括・コピー・レポート・実食管理も各専用コントローラーへ分離済み。
 *
 * @property \Authorization\Controller\Component\AuthorizationComponent $Authorization
 */
class TReservationInfoController extends ReservationBaseController
{
    /**
     * initialize メソッド
     */
    public function initialize(): void
    {
        parent::initialize();

        $this->FormProtection->setConfig('unlockedActions', [
            'checkDuplicateReservation',
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
        $userId = (int)$authUser->get('i_id_user');
        $today  = Date::today();

        // テンプレートが使うのはログイン中の利用者の項目（i_admin・i_user_level・i_id_user）だけなので、identity をそのまま渡す
        $user            = $authUser;
        $isAdmin         = UserRole::isAdmin((int)($authUser->get('i_admin') ?? 0));
        // 管理者・システム管理者も所属部屋のみ（#821）。全部屋の集計を見られるのは事務所ユーザーだけ。
        $canViewAllRooms = $this->roomQuery->isOfficeUser($userId);

        $calRoomIdQuery = $this->request->getQuery('cal_room_id');
        $scope = $this->calendarQuery->roomScope(
            $this->roomQuery->getUserRoomIds($userId),
            $canViewAllRooms,
            ($calRoomIdQuery !== null && $calRoomIdQuery !== '') ? (int)$calRoomIdQuery : null
        );
        $userRoomId      = $scope->userRoomId;
        $rooms           = $scope->rooms;
        $authorizedRooms = $rooms;
        $pickerRooms     = $scope->pickerRooms;
        $calRoomId       = $scope->calRoomId;
        [$viewStart, $viewEnd] = $this->calendarQuery->initialViewRange();

        $mealDataArray        = $this->calendarQuery->getMealCountsByDate($scope->roomFilter, $viewStart, $viewEnd);
        $myReservationDetails = $this->calendarQuery->getMyReservationDays($userId, $viewStart, $viewEnd);
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
        $roomIds = $this->calendarQuery->countableRoomIds($this->roomQuery->isOfficeUser($userId), $this->roomQuery->getUserRoomIds($userId));

        $events = CalendarEventBuilder::totalEvents(
            $this->calendarQuery->getTotalCountsByDate($startDate->format('Y-m-d'), $endDate->format('Y-m-d'), $roomIds)
        );

        return $this->apiResponseService->success($this->response, ['events' => $events]);
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

        // 集計は有効な所属部屋（削除済みの部屋を除く）だけ。index と同じ基準
        $roomIds = $this->calendarQuery->countableRoomIds($this->roomQuery->isOfficeUser($userId), $this->roomQuery->getUserRoomIds($userId));

        $events = CalendarEventBuilder::calendarEvents(
            $this->calendarQuery->getMealCountsByDate($roomIds, $startDate->format('Y-m-d'), $endDate->format('Y-m-d')),
            $this->calendarQuery->getMyReservationDays($userId, $startDate->format('Y-m-d'), $endDate->format('Y-m-d')),
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
}
