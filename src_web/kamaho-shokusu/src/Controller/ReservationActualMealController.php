<?php
declare(strict_types=1);

namespace App\Controller;

use App\Domain\ValueObject\UserRole;
use App\Service\ActualMealManagementService;
use App\Service\MealCountGridService;
use Cake\Http\Response;

/**
 * 実食確認管理・食数グリッド専用コントローラー。
 *
 * 大人向け実食入力・承認申請・食数予約Excelグリッド表示を担当する。
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
        $isOfficeUser = $this->calendarService->isOfficeUser($this->MUserGroup, $this->MRoomInfo, $userId);
        $canViewAllRooms = $isAdmin || $isOfficeUser;

        $userRoomIds    = $this->calendarService->getUserRoomIds($this->MUserGroup, $userId);
        $rooms          = $this->calendarService->getRoomsForUser($this->MRoomInfo, $userRoomIds, $isAdmin, $isOfficeUser, $isBlockLeader);

        $selectedRoomId = $this->request->getQuery('room_id')
            ? (int)$this->request->getQuery('room_id')
            : (!empty($rooms) ? (int)array_key_first($rooms) : null);
        if ($selectedRoomId !== null && !isset($rooms[$selectedRoomId])) {
            $selectedRoomId = !empty($rooms) ? (int)array_key_first($rooms) : null;
        }

        $today         = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo'));
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
                        ->where(['i_id_user' => $userId, 'i_id_room' => $selectedRoomId, 'active_flag' => 0])
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

        $userRoomIds = $this->calendarService->getUserRoomIds($this->MUserGroup, $userId);
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

        $today         = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo'));
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
                $belongsToRoom = $isAdmin || $this->MUserGroup->find()
                    ->where(['i_id_user' => $userId, 'i_id_room' => $selectedRoomId, 'active_flag' => 0])
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
                'TApprovalLog.i_approval_status'   => 3,
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

        $authUser = $this->Authentication->getIdentity();
        $actor    = (string)($authUser?->get('c_user_name') ?? 'system');

        $service = new ActualMealManagementService();
        $result  = $service->saveActualMeal(
            $this->TIndividualReservationInfo,
            $targetUid,
            $roomId,
            $date,
            $mealType,
            $checked,
            $version,
            $actor
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

        $authUser = $this->Authentication->getIdentity();
        $actor    = (string)($authUser?->get('c_user_name') ?? 'system');

        foreach ($keys as $key) {
            $targetUid = (int)($key['user_id']  ?? 0);
            $roomId    = (int)($key['room_id']   ?? 0);
            $date      = (string)($key['date']   ?? '');
            $mealType  = (int)($key['meal_type'] ?? 0);

            if (!$targetUid || !$roomId || !$mealType || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
                return $this->apiResponseService->error($this->response, '申請対象の形式が正しくありません。', 400);
            }

            if ($denied = $this->authorizeReservation('actualMealRequestApproval', [
                'i_id_room' => $roomId,
                'i_id_user' => $targetUid,
            ], true)) {
                return $denied;
            }
        }

        $service       = new ActualMealManagementService();
        $affectedTotal = $service->requestApproval($this->TIndividualReservationInfo, $keys, $actor);

        return $this->apiResponseService->success(
            $this->response,
            ['count' => $affectedTotal],
            '承認申請しました。'
        );
    }

    /**
     * 食数予約Excelグリッド画面（28日）。
     *
     * @return Response|null
     */
    public function mealCountGrid(): ?Response
    {
        $this->authorizeReservation('mealCountGrid');

        $authUser = $this->Authentication->getIdentity();
        if (!$authUser) {
            throw new \Cake\Http\Exception\UnauthorizedException('ログインが必要です。');
        }

        $loginUserId   = (int)$authUser->get('i_id_user');
        $loginName     = (string)($authUser->get('c_user_name') ?? '');
        $isAdmin       = UserRole::isAdmin((int)($authUser->get('i_admin') ?? 0));
        $isBlockLeader = UserRole::isBlockLeader((int)($authUser->get('i_admin') ?? 0));
        $loginStaffId  = $authUser->get('i_id_staff');
        $hasStaffId    = $loginStaffId !== null && $loginStaffId !== '' && $loginStaffId !== 0;
        $isStaffUser   = in_array((int)($authUser->get('i_user_level') ?? -1), [0, 7], true);
        $isOfficeUser  = $this->calendarService->isOfficeUser($this->MUserGroup, $this->MRoomInfo, $loginUserId);
        $canViewAll    = $isAdmin || $isOfficeUser;
        $canViewRoom   = $canViewAll || $isBlockLeader || $isStaffUser;
        $canUseAllMode = $canViewAll || ($isStaffUser && !$isBlockLeader);

        $session      = $this->request->getSession();
        $viewMode     = $this->request->getQuery('mode') ?? $session->read('mealCountGrid.mode') ?? 'individual';
        if (!in_array($viewMode, ['individual', 'room', 'all'], true)) {
            $viewMode = 'individual';
        }
        if (!$canUseAllMode && $viewMode === 'all') {
            $viewMode = $canViewRoom ? 'room' : 'individual';
        }
        if (!$canViewRoom && $viewMode !== 'individual') {
            $viewMode = 'individual';
        }

        $userRoomIds = $this->calendarService->getUserRoomIds($this->MUserGroup, $loginUserId);
        $allRooms    = $this->calendarService->getRoomsForUser(
            $this->MRoomInfo,
            $userRoomIds,
            $canViewAll,
            $isOfficeUser,
            $isBlockLeader
        );

        if (!$isAdmin && ($viewMode === 'room' || $viewMode === 'all')) {
            $allRooms = array_intersect_key($allRooms, array_flip($userRoomIds));
        }

        $roomIdQuery    = $this->request->getQuery('room_id');
        $sessionRoomId  = $session->read('mealCountGrid.roomId');
        $selectedRoomId = $roomIdQuery
            ? (int)$roomIdQuery
            : ($sessionRoomId !== null ? (int)$sessionRoomId : (!empty($allRooms) ? (int)array_key_first($allRooms) : null));
        if ($selectedRoomId !== null && !isset($allRooms[$selectedRoomId])) {
            $selectedRoomId = !empty($allRooms) ? (int)array_key_first($allRooms) : null;
        }

        $gridService = new MealCountGridService();
        $today       = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo'));

        $weekParam     = $this->request->getQuery('week') ?? $session->read('mealCountGrid.week');
        $weekNav       = $gridService->resolveWeekNavigation($weekParam, $isAdmin, $today);
        $weekMondayStr = $weekNav['weekMondayStr'];
        $dates         = $gridService->buildDateRange($weekMondayStr, 28);
        $periodLabel   = $gridService->buildPeriodLabel($dates);

        $userIdQuery    = $this->request->getQuery('user_id');
        $selectedUserId = $userIdQuery
            ? (int)$userIdQuery
            : ($canViewAll ? (int)($session->read('mealCountGrid.userId') ?? $loginUserId) : $loginUserId);
        if (!$canViewAll) {
            $selectedUserId = $loginUserId;
        }

        if ($viewMode === 'individual') {
            $targetUserRoomIds = $this->calendarService->getUserRoomIds($this->MUserGroup, $selectedUserId);
            $targetRooms = array_intersect_key($allRooms, array_flip($targetUserRoomIds));
        } elseif ($viewMode === 'room') {
            $targetRooms = ($selectedRoomId !== null && isset($allRooms[$selectedRoomId]))
                ? [$selectedRoomId => $allRooms[$selectedRoomId]]
                : [];
        } else {
            $targetRooms = $allRooms;
        }

        $roomUsers = [];
        foreach (array_keys($targetRooms) as $roomId) {
            $roomId = (int)$roomId;
            $users  = $gridService->getRoomUsers($this->MUserGroup, $this->MUserInfo, $roomId);

            if ($viewMode === 'individual') {
                if (!$canViewAll && $isStaffUser) {
                    $users = array_values(array_filter(
                        $users,
                        fn($u) => (int)$u['id'] === $loginUserId || (int)($u['i_user_level'] ?? 0) === 1
                    ));
                } else {
                    $users = array_values(array_filter($users, fn($u) => (int)$u['id'] === $selectedUserId));
                }
            }

            $roomUsers[$roomId] = $users;
        }

        $gridData       = $gridService->buildGrid($this->TIndividualReservationInfo, $targetRooms, $roomUsers, $dates);
        $monthlyTotals  = $gridService->buildMonthlyTotals($gridData);
        $dateCategories = $gridService->buildDateCategories($dates);

        $nameList = [];
        if ($viewMode === 'individual') {
            if ($canViewAll) {
                $activeUsers = $this->MUserInfo->find()
                    ->select(['i_id_user', 'c_user_name'])
                    ->where(['i_del_flag' => 0])
                    ->orderAsc('c_user_name')
                    ->enableHydration(false)
                    ->all();
                foreach ($activeUsers as $u) {
                    $nameList[(int)$u['i_id_user']] = (string)$u['c_user_name'];
                }
            } else {
                $nameList[$loginUserId] = $loginName;
            }
        }

        $session->write('mealCountGrid.week', $weekMondayStr);
        $session->write('mealCountGrid.mode', $viewMode);
        $session->write('mealCountGrid.roomId', $selectedRoomId);
        if ($canViewAll) {
            $session->write('mealCountGrid.userId', $selectedUserId);
        }

        $this->set([
            'allRooms'       => $allRooms,
            'selectedRoomId' => $selectedRoomId,
            'nameList'        => $nameList,
            'selectedUserId'  => $selectedUserId,
            'viewMode'        => $viewMode,
            'gridData'        => $gridData,
            'monthlyTotals'   => $monthlyTotals,
            'dateCategories'  => $dateCategories,
            'weekMondayStr'   => $weekMondayStr,
            'periodLabel'     => $periodLabel,
            'prevMonday'      => $weekNav['prevMonday'],
            'nextMonday'      => $weekNav['nextMonday'],
            'canGoPrev'       => $weekNav['canGoPrev'],
            'canGoNext'       => $weekNav['canGoNext'],
            'isAdmin'         => $isAdmin,
            'isBlockLeader'   => $isBlockLeader,
            'canViewAll'      => $canViewAll,
            'canViewRoom'     => $canViewRoom,
            'canUseAllMode'   => $canUseAllMode,
            'hasStaffId'      => $hasStaffId,
            'loginUserId'     => $loginUserId,
            'loginName'       => $loginName,
            'loginRoomIds'    => $userRoomIds,
        ]);

        $this->viewBuilder()->setLayout('default');
        $this->viewBuilder()->setTemplatePath('TReservationInfo');
        return null;
    }
}
