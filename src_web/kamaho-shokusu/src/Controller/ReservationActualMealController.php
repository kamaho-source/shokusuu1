<?php
declare(strict_types=1);

namespace App\Controller;

use App\Application\Query\ActualMealQueryInterface;
use App\Domain\Service\MealGridWeekRule;
use App\Domain\ValueObject\MealGridWeek;
use App\Domain\ValueObject\UserRole;
use App\Service\ActualMealManagementService;
use Authentication\IdentityInterface;
use Cake\Core\Configure;
use Cake\Event\EventInterface;
use Cake\Http\Exception\NotFoundException;
use Cake\Http\Exception\UnauthorizedException;
use Cake\Http\Response;
use Psr\Clock\ClockInterface;

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
     * @throws \Cake\Http\Exception\UnauthorizedException 未ログインのとき
     */
    public function actualMealManagement(ActualMealQueryInterface $actualMealQuery, ClockInterface $clock): ?Response
    {
        $this->authorizeReservation('actualMealManagement');

        $authUser = $this->Authentication->getIdentity();
        if (!$authUser) {
            throw new UnauthorizedException('ログインが必要です。');
        }

        $userId       = (int)$authUser->get('i_id_user');
        $isAdmin      = UserRole::isAdmin((int)($authUser->get('i_admin') ?? 0));
        $isOfficeUser = $this->roomQuery->isOfficeUser($userId);

        // 管理者・システム管理者も所属部屋のみ（#821）
        $rooms          = $this->calendarQuery->getRoomsForUser($this->roomQuery->getUserRoomIds($userId), false, $isOfficeUser);
        $selectedRoomId = $this->selectRoomId($rooms);
        $week           = $this->resolveWeek($isAdmin, $clock);
        $weekMondayStr  = $week->mondayString();
        $adultUsers     = [];
        $gridData       = ['dates' => [], 'meals' => [], 'grid' => [], 'versions' => []];

        if ($selectedRoomId) {
            $adultUsers = $actualMealQuery->getAdultUsers($selectedRoomId);
            if ($isAdmin) {
                $adultUsers = $this->withSelf($adultUsers, $this->selfRow($authUser), $selectedRoomId);
            }
            $gridData = $actualMealQuery->getWeekGrid($adultUsers, $weekMondayStr);
        }

        $prevMonday = $week->prevMonday;
        $nextMonday = $week->nextMonday;
        $canGoPrev  = $week->canGoPrev;
        $canGoNext  = $week->canGoNext;

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
     * @throws \Cake\Http\Exception\UnauthorizedException 未ログインのとき
     */
    public function myActualMeal(ActualMealQueryInterface $actualMealQuery, ClockInterface $clock): ?Response
    {
        $this->authorizeReservation('myActualMeal');

        $authUser = $this->Authentication->getIdentity();
        if (!$authUser) {
            throw new UnauthorizedException('ログインが必要です。');
        }

        $userId        = (int)$authUser->get('i_id_user');
        $isAdmin       = UserRole::isAdmin((int)($authUser->get('i_admin') ?? 0));
        $isBlockLeader = UserRole::isBlockLeader((int)($authUser->get('i_admin') ?? 0));
        $canProxyActualMeal = $isAdmin || $isBlockLeader;

        $rooms          = $this->roomQuery->getRoomsByIds($this->roomQuery->getUserRoomIds($userId));
        $selectedRoomId = $this->selectRoomId($rooms);
        $week           = $this->resolveWeek($isAdmin, $clock);
        $weekMondayStr  = $week->mondayString();
        $prevMonday     = $week->prevMonday;
        $nextMonday     = $week->nextMonday;
        $canGoPrev      = $week->canGoPrev;
        $canGoNext      = $week->canGoNext;

        $self        = $this->selfRow($authUser);
        $targetUsers = [$self];
        if ($canProxyActualMeal && $selectedRoomId) {
            $targetUsers = $this->withSelf($actualMealQuery->getAdultUsers($selectedRoomId), $self, $selectedRoomId);
        }

        $selectedUserId = $this->request->getQuery('user_id') ? (int)$this->request->getQuery('user_id') : $userId;
        $targetUserIds  = array_map(static fn(array $targetUser): int => (int)$targetUser['id'], $targetUsers);
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

        $gridData = ['dates' => [], 'meals' => [], 'grid' => [], 'versions' => []];
        if ($selectedRoomId && $selectedTargetUser !== null) {
            $gridData = $actualMealQuery->getWeekGrid([$selectedTargetUser], $weekMondayStr);
        }

        $weekDates       = MealGridWeekRule::dateRange($weekMondayStr, 7);
        $rejectionBanner = $actualMealQuery->findLatestRejection($selectedUserId, $weekDates[0], $weekDates[6]);

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
     * 部屋の選択（room_id が候補になければ先頭の部屋。部屋が無ければ null）。
     *
     * @param array<int, string> $rooms
     */
    private function selectRoomId(array $rooms): ?int
    {
        $roomId = $this->request->getQuery('room_id') ? (int)$this->request->getQuery('room_id') : null;
        if ($roomId !== null && isset($rooms[$roomId])) {
            return $roomId;
        }

        return !empty($rooms) ? (int)array_key_first($rooms) : null;
    }

    /**
     * 表示する週。管理者は 2 か月前の週まで、それ以外は今週から。先は 4 週まで。前の週へ移れるのは管理者だけ。
     */
    private function resolveWeek(bool $isAdmin, ClockInterface $clock): MealGridWeek
    {
        $week = $this->request->getQuery('week');

        // 週の境界は日付だけで比べる（時刻が残ると、同じ月曜日でも前後の判定がずれる）
        return MealGridWeekRule::resolve(is_string($week) ? $week : null, $isAdmin, $clock->now()->setTime(0, 0), true);
    }

    /**
     * ログイン中の利用者を、実食の一覧と同じ形にする。
     *
     * @return array{id: int, name: string, staff_id: string}
     */
    private function selfRow(IdentityInterface $authUser): array
    {
        $data = $authUser->getOriginalData();

        return [
            'id'       => (int)($data['i_id_user'] ?? 0),
            'name'     => (string)($data['c_user_name'] ?? ''),
            'staff_id' => (string)($data['i_id_staff'] ?? ''),
        ];
    }

    /**
     * 一覧に自分がいなくても、その部屋に所属していれば先頭に足す（職員番号の無い管理者など）。
     *
     * @param list<array{id: int, name: string, staff_id: string}> $users
     * @param array{id: int, name: string, staff_id: string} $self
     * @return list<array{id: int, name: string, staff_id: string}>
     */
    private function withSelf(array $users, array $self, int $roomId): array
    {
        if (in_array($self['id'], array_column($users, 'id'), true) || !$this->roomQuery->isActiveMember($self['id'], $roomId)) {
            return $users;
        }
        array_unshift($users, $self);

        return $users;
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
