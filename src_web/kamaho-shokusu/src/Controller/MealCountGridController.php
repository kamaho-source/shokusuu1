<?php
declare(strict_types=1);

namespace App\Controller;

use App\Application\Query\MealCountGridQueryInterface;
use App\Domain\Service\MealGridViewScope;
use App\Domain\Service\MealGridWeekRule;
use App\Domain\ValueObject\UserRole;
use Cake\Chronos\ChronosDate;
use Cake\Http\Exception\UnauthorizedException;
use Cake\Http\Response;
use Psr\Clock\ClockInterface;

/**
 * エクセル食数予約（食数予約の 28 日グリッド）の画面。
 *
 * URL は /TReservationInfo/meal-count-grid（routes.php）。保存は ReservationToggleController::bulkToggle。
 * 誰が何を見られるかは MealGridViewScope、表示期間は MealGridWeekRule が決める。
 */
class MealCountGridController extends ReservationBaseController
{
    /**
     * 食数予約Excelグリッド画面（28日）。
     *
     * @return Response|null
     * @throws \Cake\Http\Exception\UnauthorizedException 未ログインのとき
     * @throws \Cake\Http\Exception\BadRequestException week が日付でないとき
     */
    public function mealCountGrid(MealCountGridQueryInterface $gridQuery, ClockInterface $clock): ?Response
    {
        $this->authorizeReservation('mealCountGrid');

        $authUser = $this->request->getAttribute('identity');
        if (!$authUser) {
            throw new UnauthorizedException('ログインが必要です。');
        }

        $loginUserId  = (int)$authUser->get('i_id_user');
        $loginName    = (string)($authUser->get('c_user_name') ?? '');
        $iAdmin       = (int)($authUser->get('i_admin') ?? 0);
        $loginStaffId = $authUser->get('i_id_staff');
        $hasStaffId   = $loginStaffId !== null && $loginStaffId !== '' && $loginStaffId !== 0;
        $isOfficeUser = $this->roomQuery->isOfficeUser($loginUserId);
        $scope        = MealGridViewScope::forUser($iAdmin, (int)($authUser->get('i_user_level') ?? -1), $isOfficeUser);

        $session  = $this->request->getSession();
        $mode     = $this->request->getQuery('mode') ?? $session->read('mealCountGrid.mode');
        $viewMode = $scope->resolveMode(is_string($mode) ? $mode : null);

        // 有効な所属部屋（退出済み・削除済みを除く）。管理者以外の編集可否（loginRoomIds）はこれで判定する
        $userRoomIds = array_keys($this->calendarQuery->getRoomsForUser($this->roomQuery->getUserRoomIds($loginUserId), false));
        // 全部屋（削除済みを除く）を候補にできるのは管理者・システム管理者と事務所ユーザー。
        // 他の予約画面と違い、エクセル食数予約だけは管理者が全部屋を閲覧・編集できる（#821）
        $allRooms = $scope->visibleRooms(
            $this->calendarQuery->getRoomsForUser($userRoomIds, $scope->canViewAll, $isOfficeUser),
            $userRoomIds,
            $viewMode,
        );

        $roomIdQuery    = $this->request->getQuery('room_id');
        $sessionRoomId  = $session->read('mealCountGrid.roomId');
        $selectedRoomId = $scope->resolveRoomId(
            $roomIdQuery ? (int)$roomIdQuery : null,
            $sessionRoomId !== null ? (int)$sessionRoomId : null,
            $allRooms,
        );

        $today         = $clock->now();
        $week          = MealGridWeekRule::resolve($this->queryDate('week') ?? $session->read('mealCountGrid.week'), $scope->isAdmin, $today);
        $weekMondayStr = $week->mondayString();
        $dates         = MealGridWeekRule::dateRange($weekMondayStr);

        // 選べる利用者。管理者・システム管理者と事務所ユーザーは全員
        $selectableUsers = $scope->canViewAll ? $gridQuery->getSelectableUsers() : [];
        $userIdQuery     = $this->request->getQuery('user_id');
        $sessionUserId   = $session->read('mealCountGrid.userId');
        $selectedUserId  = $scope->resolveUserId(
            $userIdQuery ? (int)$userIdQuery : null,
            $sessionUserId !== null ? (int)$sessionUserId : null,
            $loginUserId,
            $selectableUsers,
        );

        $targetRooms = $scope->targetRooms(
            $viewMode,
            $allRooms,
            $selectedRoomId,
            $viewMode === MealGridViewScope::MODE_INDIVIDUAL ? $this->roomQuery->getUserRoomIds($selectedUserId) : [],
        );

        // 部屋ごとにクエリを投げると部屋数ぶんの N+1 になるため、まとめて1回で引く
        $roomUsers = $gridQuery->getRoomUsers(array_keys($targetRooms));
        $rowUserId = $scope->rowUserId($viewMode, $selectedUserId);
        if ($rowUserId !== null) {
            foreach ($roomUsers as $roomId => $users) {
                $roomUsers[$roomId] = array_values(array_filter($users, fn($u) => $u->id === $rowUserId));
            }
        }

        $gridData = $gridQuery->getGrid($targetRooms, $roomUsers, $dates);

        $session->write('mealCountGrid.week', $weekMondayStr);
        $session->write('mealCountGrid.mode', $viewMode);
        $session->write('mealCountGrid.roomId', $selectedRoomId);
        if ($scope->canViewAll) {
            $session->write('mealCountGrid.userId', $selectedUserId);
        }

        $this->set([
            'allRooms'        => $allRooms,
            'selectedRoomId'  => $selectedRoomId,
            'nameList'        => $scope->nameList($viewMode, $selectableUsers, $loginUserId, $loginName),
            'selectedUserId'  => $selectedUserId,
            'viewMode'        => $viewMode,
            'gridData'        => $gridData,
            'monthlyTotals'   => $gridData->totalsByMeal(),
            'dateCategories'  => MealGridWeekRule::dateCategories($dates, new ChronosDate($today)),
            'weekMondayStr'   => $weekMondayStr,
            'periodLabel'     => MealGridWeekRule::periodLabel($dates),
            'prevMonday'      => $week->prevMonday,
            'nextMonday'      => $week->nextMonday,
            'canGoPrev'       => $week->canGoPrev,
            'canGoNext'       => $week->canGoNext,
            'isAdmin'         => $scope->isAdmin,
            'isBlockLeader'   => UserRole::isBlockLeader($iAdmin),
            'canViewAll'      => $scope->canViewAll,
            'canViewRoom'     => $scope->canViewRoom,
            'canUseAllMode'   => $scope->canUseAllMode,
            'hasStaffId'      => $hasStaffId,
            'loginUserId'     => $loginUserId,
            'loginName'       => $loginName,
            // 編集できる部屋。管理者は全部屋（エクセル食数予約のみ）
            'loginRoomIds'    => $scope->editableRoomIds($allRooms, $userRoomIds),
        ]);

        $this->viewBuilder()->setLayout('default');
        $this->viewBuilder()->setTemplatePath('TReservationInfo');
        return null;
    }
}
