<?php
declare(strict_types=1);

namespace App\Controller;

use App\Domain\ValueObject\ApprovalStatus;
use App\Domain\ValueObject\UserRole;
use App\Infrastructure\Persistence\Table\MUserGroupTable;
use App\Service\ActualMealManagementService;
use Cake\Core\Configure;
use Cake\Event\EventInterface;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Response;
use Cake\I18n\DateTime;

/**
 * 実食確認管理専用コントローラー。
 *
 * 大人向け実食入力・承認申請を担当する。全アクションが実食機能(Features.actualMeal)の対象。
 * エクセル食数予約（mealCountGrid）は予約機能なので MealCountGridController にある。
 */
class ReservationActualMealController extends ReservationBaseController
{
    public function initialize(): void
    {
        parent::initialize();

        $this->FormProtection->setConfig('unlockedActions', [
            'actualMealSave',
            'actualMealRequestApproval',
        ]);
    }

    /**
     * 実食機能が無効のときは全アクションを 404 にする。
     * （ダッシュボードから導線を隠しているが、直接URLアクセスも塞ぐ）
     *
     * @param \Cake\Event\EventInterface $event
     * @return void
     */
    public function beforeFilter(EventInterface $event)
    {
        parent::beforeFilter($event);

        if (!Configure::read('Features.actualMeal')) {
            throw new NotFoundException('実食入力機能は現在無効です。');
        }
    }

    /**
     * 実食確認管理画面（大人限定）。
     *
     * @return Response|null
     */
    public function actualMealManagement(): ?Response
    {
        $this->authorizeReservation('actualMealManagement');

        $authUser = $this->Authentication->getIdentity();
        if (!$authUser) {
            throw new \Cake\Http\Exception\UnauthorizedException('ログインが必要です。');
        }

        $userId  = (int)$authUser->get('i_id_user');
        $isAdmin = UserRole::isAdmin((int)($authUser->get('i_admin') ?? 0));
        $isBlockLeader = UserRole::isBlockLeader((int)($authUser->get('i_admin') ?? 0));
        $isOfficeUser = $this->roomQuery->isOfficeUser($userId);

        // 管理者・システム管理者も所属部屋のみ（#821）
        $userRoomIds    = $this->roomQuery->getUserRoomIds($userId);
        $rooms          = $this->calendarQuery->getRoomsForUser($userRoomIds, false, $isOfficeUser);

        $selectedRoomId = $this->request->getQuery('room_id')
            ? (int)$this->request->getQuery('room_id')
            : (!empty($rooms) ? (int)array_key_first($rooms) : null);
        if ($selectedRoomId !== null && !isset($rooms[$selectedRoomId])) {
            $selectedRoomId = !empty($rooms) ? (int)array_key_first($rooms) : null;
        }

        $today         = DateTime::now('Asia/Tokyo');
        $defaultMonday = $today->modify('monday this week')->format('Y-m-d');
        $weekParam      = $this->request->getQuery('week') ?? $defaultMonday;

        try {
            $weekDate = new \DateTimeImmutable($weekParam, new \DateTimeZone('Asia/Tokyo'));
        } catch (\Throwable $e) {
            $weekDate = new \DateTimeImmutable($defaultMonday, new \DateTimeZone('Asia/Tokyo'));
        }
        $weekMonday = (int)$weekDate->format('N') === 1
            ? $weekDate
            : $weekDate->modify('monday this week');

        $service      = new ActualMealManagementService();
        $oldestMonday = $isAdmin ? $service->getAdminOldestAllowedMonday() : $today->modify('monday this week');
        $futureMonday = $today->modify('monday this week')->modify('+4 weeks');

        if (!$isAdmin && $weekMonday < $today->modify('monday this week')) {
            $weekMonday = new \DateTimeImmutable($defaultMonday, new \DateTimeZone('Asia/Tokyo'));
        }
        if ($weekMonday < $oldestMonday) {
            $weekMonday = $oldestMonday;
        }
        if ($weekMonday > $futureMonday) {
            $weekMonday = $futureMonday;
        }

        $weekMondayStr = $weekMonday->format('Y-m-d');
        $adultUsers    = [];
        $gridData      = ['dates' => [], 'meals' => [], 'grid' => [], 'versions' => []];

        if ($selectedRoomId) {
            $adultUsers = $service->getAdultUsers($this->MUserGroup, $this->MUserInfo, $selectedRoomId);

            if ($isAdmin) {
                $alreadyIn = array_filter($adultUsers, fn($u) => (int)$u['id'] === $userId);
                if (empty($alreadyIn)) {
                    $inRoom = $this->MUserGroup->find()
                        ->where(['i_id_user' => $userId, 'i_id_room' => $selectedRoomId, ...MUserGroupTable::activeCondition('')])
                        ->count() > 0;
                    if ($inRoom) {
                        array_unshift($adultUsers, [
                            'id'       => $userId,
                            'name'     => (string)($authUser->get('c_user_name') ?? ''),
                            'staff_id' => (string)($authUser->get('i_id_staff') ?? ''),
                        ]);
                    }
                }
            }

            $gridData = $service->buildWeekGrid($this->TIndividualReservationInfo, $adultUsers, $weekMondayStr);
        }

        $prevMonday = $weekMonday->modify('-7 days');
        $nextMonday = $weekMonday->modify('+7 days');
        $canGoPrev  = $isAdmin && $prevMonday >= $oldestMonday;
        $canGoNext  = $nextMonday <= $futureMonday;

        $this->set(compact(
            'rooms', 'selectedRoomId', 'adultUsers', 'gridData',
            'weekMondayStr', 'prevMonday', 'nextMonday', 'canGoPrev', 'canGoNext', 'isAdmin'
        ));

        $this->viewBuilder()->setTemplatePath('TReservationInfo');
        return null;
    }

    /**
     * 個人向け実食入力画面（職員が自分の実食を入力する）。
     *
     * @return Response|null
     */
    public function myActualMeal(): ?Response
    {
        $this->authorizeReservation('myActualMeal');

        $authUser = $this->Authentication->getIdentity();
        if (!$authUser) {
            throw new \Cake\Http\Exception\UnauthorizedException('ログインが必要です。');
        }

        $userId        = (int)$authUser->get('i_id_user');
        $isAdmin       = UserRole::isAdmin((int)($authUser->get('i_admin') ?? 0));
        $isBlockLeader = UserRole::isBlockLeader((int)($authUser->get('i_admin') ?? 0));
        $canProxyActualMeal = $isAdmin || $isBlockLeader;

        $userRoomIds = $this->roomQuery->getUserRoomIds($userId);
        $rooms       = [];
        if (!empty($userRoomIds)) {
            $rooms = $this->MRoomInfo->find()
                ->where(['i_id_room IN' => $userRoomIds, 'i_del_flg' => 0])
                ->orderAsc('i_disp_no')
                ->all()
                ->combine('i_id_room', 'c_room_name')
                ->toArray();
        }

        $selectedRoomId = $this->request->getQuery('room_id')
            ? (int)$this->request->getQuery('room_id')
            : (!empty($rooms) ? (int)array_key_first($rooms) : null);
        if ($selectedRoomId !== null && !isset($rooms[$selectedRoomId])) {
            $selectedRoomId = !empty($rooms) ? (int)array_key_first($rooms) : null;
        }

        $today         = DateTime::now('Asia/Tokyo');
        $defaultMonday = $today->modify('monday this week')->format('Y-m-d');
        $weekParam      = $this->request->getQuery('week') ?? $defaultMonday;

        try {
            $weekDate = new \DateTimeImmutable($weekParam, new \DateTimeZone('Asia/Tokyo'));
        } catch (\Throwable $e) {
            $weekDate = new \DateTimeImmutable($defaultMonday, new \DateTimeZone('Asia/Tokyo'));
        }
        $weekMonday = (int)$weekDate->format('N') === 1
            ? $weekDate
            : $weekDate->modify('monday this week');

        $service      = new ActualMealManagementService();
        $oldestMonday = $isAdmin ? $service->getAdminOldestAllowedMonday() : new \DateTimeImmutable($defaultMonday, new \DateTimeZone('Asia/Tokyo'));
        $futureMonday = $today->modify('monday this week')->modify('+4 weeks');

        if (!$isAdmin && $weekMonday < new \DateTimeImmutable($defaultMonday, new \DateTimeZone('Asia/Tokyo'))) {
            $weekMonday = new \DateTimeImmutable($defaultMonday, new \DateTimeZone('Asia/Tokyo'));
        }
        if ($weekMonday < $oldestMonday) {
            $weekMonday = $oldestMonday;
        }
        if ($weekMonday > $futureMonday) {
            $weekMonday = $futureMonday;
        }

        $weekMondayStr = $weekMonday->format('Y-m-d');
        $prevMonday    = $weekMonday->modify('-7 days');
        $nextMonday    = $weekMonday->modify('+7 days');
        $canGoPrev     = $isAdmin && $prevMonday >= $oldestMonday;
        $canGoNext     = $nextMonday <= $futureMonday;

        $targetUsers = [[
            'id'       => $userId,
            'name'     => (string)$authUser->get('c_user_name'),
            'staff_id' => (string)($authUser->get('i_id_staff') ?? ''),
        ]];

        if ($canProxyActualMeal && $selectedRoomId) {
            $targetUsers = $service->getAdultUsers($this->MUserGroup, $this->MUserInfo, $selectedRoomId);
            $hasSelf     = array_filter($targetUsers, fn($targetUser) => (int)$targetUser['id'] === $userId);
            if (empty($hasSelf) && $selectedRoomId !== null) {
                $belongsToRoom = $this->MUserGroup->find()
                    ->where(['i_id_user' => $userId, 'i_id_room' => $selectedRoomId, ...MUserGroupTable::activeCondition('')])
                    ->count() > 0;
                if ($belongsToRoom) {
                    array_unshift($targetUsers, [
                        'id'       => $userId,
                        'name'     => (string)$authUser->get('c_user_name'),
                        'staff_id' => (string)($authUser->get('i_id_staff') ?? ''),
                    ]);
                }
            }
        }

        $selectedUserId   = $this->request->getQuery('user_id') ? (int)$this->request->getQuery('user_id') : $userId;
        $targetUserIds    = array_map(static fn(array $targetUser): int => (int)$targetUser['id'], $targetUsers);
        if (!in_array($selectedUserId, $targetUserIds, true)) {
            $selectedUserId = !empty($targetUsers) ? (int)$targetUsers[0]['id'] : $userId;
        }

        $selectedTargetUser = null;
        foreach ($targetUsers as $targetUser) {
            if ((int)$targetUser['id'] === $selectedUserId) {
                $selectedTargetUser = $targetUser;
                break;
            }
        }

        $selectedUsers = $selectedTargetUser ? [$selectedTargetUser] : [];
        $gridData      = ['dates' => [], 'meals' => [], 'grid' => [], 'versions' => []];

        if ($selectedRoomId && !empty($selectedUsers)) {
            $gridData = $service->buildWeekGrid($this->TIndividualReservationInfo, $selectedUsers, $weekMondayStr);
        }

        $rejectionBanner = null;
        $weekSundayStr   = (new \DateTimeImmutable($weekMondayStr))->modify('+6 days')->format('Y-m-d');
        $latestRejection = $this->fetchTable('TApprovalLog')->find()
            ->contain(['Approvers'])
            ->where([
                'TApprovalLog.i_id_user'          => $selectedUserId,
                'TApprovalLog.i_approval_status'   => ApprovalStatus::REJECTED,
                'TApprovalLog.d_reservation_date >=' => $weekMondayStr,
                'TApprovalLog.d_reservation_date <=' => $weekSundayStr,
            ])
            ->orderDesc('TApprovalLog.dt_create')
            ->first();

        if ($latestRejection !== null) {
            $rejectionBanner = [
                'reason'   => (string)($latestRejection->c_reject_reason ?: ''),
                'approver' => (string)($latestRejection->approver?->c_user_name ?? ''),
                'date'     => $latestRejection->dt_create?->format('Y-m-d H:i') ?? '',
            ];
        }

        $this->set(compact(
            'rooms', 'selectedRoomId', 'gridData', 'targetUsers', 'selectedUserId', 'selectedTargetUser',
            'weekMondayStr', 'prevMonday', 'nextMonday',
            'canGoPrev', 'canGoNext', 'isAdmin', 'isBlockLeader', 'canProxyActualMeal',
            'rejectionBanner'
        ));

        $this->viewBuilder()->setTemplatePath('TReservationInfo');
        return null;
    }

    /**
     * 実食実績保存API（POST）。
     *
     * @return Response|null
     */
    public function actualMealSave(): ?Response
    {
        $this->request->allowMethod(['post']);

        $data     = $this->request->getData();
        $roomId   = (int)($data['room_id']   ?? 0);
        $date     = (string)($data['date']   ?? '');
        $mealType = (int)($data['meal_type'] ?? 0);
        $targetUid = (int)($data['user_id']  ?? 0);
        $checked   = (bool)((int)($data['checked'] ?? 0));
        $version   = (int)($data['version']  ?? 1);

        if (!$roomId || !$date || !$mealType || !$targetUid) {
            $this->Authorization->skipAuthorization();
            return $this->apiResponseService->error($this->response, '必要なパラメータが不足しています。', 400);
        }

        if ($denied = $this->authorizeReservation('actualMealSave', [
            'i_id_room' => $roomId,
            'i_id_user' => $targetUid,
        ], true)) {
            return $denied;
        }

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
            return $this->apiResponseService->error($this->response, '日付の形式が正しくありません。', 400);
        }

        $authUser     = $this->Authentication->getIdentity();
        $actor        = (string)($authUser?->get('c_user_name') ?? 'system');
        $actorLoginId = (string)($authUser?->get('c_login_account') ?? '');

        // 存在しない・削除済みの部屋には、管理者でも書き込めない。
        // 対象ユーザーが部屋に有効所属していること（既に行がある場合の更新は除く）。管理者も同じ（#821）。
        $roomAccess = $this->roomQuery;
        if (!$roomAccess->isRoomAvailable($roomId)) {
            return $this->apiResponseService->error($this->response, '選択された部屋は利用できません。', 403);
        }
        if (!$roomAccess->isActiveMember($targetUid, $roomId)) {
            $hasRow = $this->TIndividualReservationInfo->exists([
                'i_id_user'          => $targetUid,
                'd_reservation_date' => $date,
                'i_id_room'          => $roomId,
                'i_reservation_type' => $mealType,
            ]);
            if (!$hasRow) {
                return $this->apiResponseService->error($this->response, '対象ユーザーは指定された部屋に所属していません。', 403);
            }
        }

        $service = new ActualMealManagementService();
        $result  = $service->saveActualMeal(
            $this->TIndividualReservationInfo,
            $targetUid,
            $roomId,
            $date,
            $mealType,
            $checked,
            $version,
            $actor,
            $actorLoginId
        );

        if (!$result['ok']) {
            return $this->apiResponseService->error($this->response, $result['message'], 409);
        }

        return $this->apiResponseService->success($this->response, ['version' => $result['version'] ?? 1], $result['message']);
    }

    /**
     * 実食承認申請API（POST）。
     *
     * @return Response|null
     */
    public function actualMealRequestApproval(): ?Response
    {
        if ($denied = $this->authorizeReservation('actualMealRequestApproval', [], true)) {
            return $denied;
        }

        $this->request->allowMethod(['post']);

        $keys = (array)($this->request->getData('keys') ?? []);
        if (empty($keys)) {
            return $this->apiResponseService->error($this->response, '申請対象が指定されていません。', 400);
        }

        $authUser     = $this->Authentication->getIdentity();
        $actor        = (string)($authUser?->get('c_user_name') ?? 'system');
        $actorLoginId = (string)($authUser?->get('c_login_account') ?? '');

        $authorizedPairs = [];
        foreach ($keys as $key) {
            $targetUid = (int)($key['user_id']  ?? 0);
            $roomId    = (int)($key['room_id']   ?? 0);
            $date      = (string)($key['date']   ?? '');
            $mealType  = (int)($key['meal_type'] ?? 0);

            if (!$targetUid || !$roomId || !$mealType || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                return $this->apiResponseService->error($this->response, '申請対象の形式が正しくありません。', 400);
            }

            // 認可の結果は部屋と対象者だけで決まるため、同じ組は1回だけ問い合わせる
            $authKey = $roomId . ':' . $targetUid;
            if (isset($authorizedPairs[$authKey])) {
                continue;
            }
            if ($denied = $this->authorizeReservation('actualMealRequestApproval', [
                'i_id_room' => $roomId,
                'i_id_user' => $targetUid,
            ], true)) {
                return $denied;
            }
            $authorizedPairs[$authKey] = true;
        }

        $service       = new ActualMealManagementService();
        $affectedTotal = $service->requestApproval($this->TIndividualReservationInfo, $keys, $actor, $actorLoginId);

        return $this->apiResponseService->success(
            $this->response,
            ['count' => $affectedTotal],
            '承認申請しました。'
        );
    }
}
