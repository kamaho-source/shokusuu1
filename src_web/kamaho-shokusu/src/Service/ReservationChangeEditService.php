<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Table\TIndividualReservationInfoTable;
use App\Domain\ValueObject\UserRole;
use App\Exception\OptimisticLockConflictException;
use Cake\I18n\DateTime;
use Cake\ORM\Table;

class ReservationChangeEditService
{
    private RoomAccessService $roomAccessService;

    public function __construct(?RoomAccessService $roomAccessService = null)
    {
        $this->roomAccessService = $roomAccessService ?? new RoomAccessService();
    }

    public function userHasRoomAccess(int $userId): bool
    {
        return $this->roomAccessService->hasAnyAffiliation($userId);
    }

    public function getAllowedRooms($loginUser, ?int $roomId, Table $userGroupTable, Table $roomTable): array
    {
        $loginUid = (int)($loginUser?->get('i_id_user') ?? 0);

        $allowedRooms = $this->roomAccessService->getAccessibleRooms($roomTable, $loginUid);

        if (empty($allowedRooms) && $roomId && $this->roomAccessService->userCanAccessRoom($loginUid, $roomId)) {
            $one = $roomTable->find()
                ->select(['i_id_room', 'c_room_name'])
                ->where(['i_id_room' => $roomId])
                ->first();
            if ($one) {
                $allowedRooms = [(int)$one->i_id_room => (string)$one->c_room_name];
            }
        }

        return $allowedRooms;
    }

    /**
     * 許可部屋の中から、指定日に予約レコードが存在する部屋IDを返す。
     * 該当がなければ先頭の部屋IDを返す。
     *
     * @param array<int, string> $allowedRooms
     */
    public function resolveDefaultRoomId(array $allowedRooms, string $date, Table $reservationTable): ?int
    {
        if (empty($allowedRooms)) {
            return null;
        }

        if (count($allowedRooms) === 1) {
            return (int)array_key_first($allowedRooms);
        }

        $row = $reservationTable->find()
            ->select(['i_id_room'])
            ->where([
                'd_reservation_date'    => $date,
                'i_id_room IN'          => array_keys($allowedRooms),
                'i_reservation_type IN' => [1, 2, 3, 4],
            ])
            ->first();

        if ($row) {
            return (int)$row->i_id_room;
        }

        return (int)array_key_first($allowedRooms);
    }

    public function buildContext(
        int $roomId,
        string $date,
        Table $roomTable,
        Table $userGroupTable,
        Table $userTable,
        Table $reservationTable
    ): array {
        $room = $roomTable->find()
            ->select(['i_id_room','c_room_name'])
            ->where(['i_id_room' => $roomId])
            ->first();
        if (!$room) {
            throw new \Cake\Http\Exception\NotFoundException(__('部屋が見つかりません。'));
        }

        $baseUserIds = $userGroupTable->find()
            ->select(['i_id_user'])
            ->contain(['MUserInfo'])
            ->where([
                'MUserGroup.i_id_room'   => $roomId,
                'MUserGroup.active_flag' => 0,
                'MUserInfo.i_del_flag'   => 0,
            ])
            ->enableHydration(false)->all()->extract('i_id_user')->toList();
        $baseUserIds = array_values(array_filter($baseUserIds, fn($id) => $id !== null && $id > 0));
        if (empty($baseUserIds)) {
            $baseUserIds = [-1];
        }

        $userEntities = $userTable->find()
            ->select(['i_id_user', 'c_user_name', 'i_user_level'])
            ->where(['i_id_user IN' => $baseUserIds, 'i_del_flag' => 0])
            ->all();

        $users = [];
        foreach ($userEntities as $userEntity) {
            $users[] = [
                'id'           => (int)$userEntity->i_id_user,
                'name'         => (string)$userEntity->c_user_name,
                'i_user_level' => (int)$userEntity->i_user_level,
            ];
        }
        usort($users, fn($a,$b) => strcmp($a['name'], $b['name']));
        $userIdList = array_map(fn($u) => $u['id'], $users);

        $reservations = $reservationTable->find()
            ->contain(['MRoomInfo'])
            ->where([
                'd_reservation_date'    => $date,
                'i_reservation_type IN' => [1,2,3,4],
                'i_id_user IN'          => $userIdList ?: [-1],
            ])->all();

        $userReservations = [];
        foreach ($reservations as $r) {
            $userReservations[(int)$r->i_id_user][(int)$r->i_reservation_type] = [
                'room_id'       => (int)$r->i_id_room,
                'eat_flag'      => (int)$r->eat_flag,
                'room_name'     => (string)($r->m_room_info->c_room_name ?? '不明な部屋'),
                'i_change_flag' => $r->i_change_flag !== null ? (int)$r->i_change_flag : null,
            ];
        }

        return [
            'room' => $room,
            'users' => $users,
            'userIdList' => $userIdList,
            'userReservations' => $userReservations,
        ];
    }

    public function buildUsersForJson(array $users, $loginUser, bool $isRoomManager = false, bool $isBlockLeaderInRoom = false): array
    {
        $isAdmin       = $loginUser && UserRole::isAdmin((int)($loginUser->get('i_admin') ?? 0));
        $isLoginStaff  = $loginUser && UserRole::isStaffLevel((int)($loginUser->get('i_user_level') ?? -1));
        $loginUid      = $loginUser?->get('i_id_user');
        // 管理者・部屋管理者・その部屋所属のブロック長はすべてのユーザーを編集できる（職員は子供のみ）
        $canEditAll    = $isRoomManager || $isAdmin || $isBlockLeaderInRoom;

        $usersForJson = [];
        foreach ($users as $u) {
            // レベル不明時は職員扱いにして編集不可へ倒す（fail-closed）
            $isTargetChild = ((int)($u['i_user_level'] ?? 0) === 1);
            $isSelf        = $loginUid && ((int)$loginUid === (int)$u['id']);
            $allowEdit     = $canEditAll || $isSelf || ($isLoginStaff && $isTargetChild);
            $usersForJson[] = [
                'id'           => $u['id'],
                'name'         => $u['name'],
                'i_user_level' => $u['i_user_level'],
                'userLevel'    => $u['i_user_level'],
                'isStaff'      => $u['i_user_level'] !== null && UserRole::isStaffLevel((int)$u['i_user_level']),
                'allowEdit'    => $allowEdit,
            ];
        }

        return $usersForJson;
    }

    public function processUpdate(
        array $usersData,
        array $userIdList,
        string $date,
        int $roomId,
        $loginUser,
        Table $reservationTable,
        Table $userTable,
        bool $isRoomManager = false
    ): array {
        $connection = $reservationTable->getConnection();
        $connection->begin();

        $updated = [];
        $created = [];
        $skipped = [];

        try {
            // ログインユーザーの編集権限を確定する（buildUsersForJson と同一ロジック）
            $loginUid     = $loginUser ? (int)$loginUser->get('i_id_user') : 0;
            $isAdmin      = $loginUser && UserRole::isAdmin((int)($loginUser->get('i_admin') ?? 0));
            $isLoginStaff = $loginUser && UserRole::isStaffLevel((int)($loginUser->get('i_user_level') ?? -1));
            $canEditAll   = $isRoomManager || $isAdmin;

            $allowedMap = array_fill_keys(array_map('intval', $userIdList), true);
            $targetUserIds = [];
            foreach ($usersData as $uid => $_) {
                $uid = (int)$uid;
                if (isset($allowedMap[$uid])) {
                    $targetUserIds[$uid] = true;
                }
            }
            $targetUserIds = array_keys($targetUserIds);

            $userLevelMap = [];
            if (!empty($targetUserIds)) {
                $levelRows = $userTable->find()
                    ->select(['i_id_user', 'i_user_level'])
                    ->where(['i_id_user IN' => $targetUserIds])
                    ->all();
                foreach ($levelRows as $row) {
                    $userLevelMap[(int)$row->i_id_user] = (int)$row->i_user_level;
                }
            }

            $isLastMinute = TIndividualReservationInfoTable::isLastMinute($date);
            $existingByRoom = [];
            $existingAny = [];
            if (!empty($targetUserIds)) {
                $existingRows = $reservationTable->find()
                    ->enableAutoFields(false)
                    ->select([
                        'i_id_user',
                        'd_reservation_date',
                        'i_reservation_type',
                        'i_id_room',
                        'eat_flag',
                        'i_change_flag',
                        'i_version',
                    ])
                    ->where([
                        'i_id_user IN' => $targetUserIds,
                        'd_reservation_date' => $date,
                        'i_reservation_type IN' => [1, 2, 3, 4],
                    ])
                    ->all();
                foreach ($existingRows as $row) {
                    $uid = (int)$row->i_id_user;
                    $mealType = (int)$row->i_reservation_type;
                    $rid = (int)$row->i_id_room;
                    $existingByRoom[$uid][$mealType][$rid] = $row;
                    // 部屋を書き換えて移すときは、有効な行を優先して選ぶ（取消済みの行を移すと二重予約になる）
                    if (!isset($existingAny[$uid][$mealType]) || TIndividualReservationInfoTable::isEffectiveRow($row)) {
                        $existingAny[$uid][$mealType] = $row;
                    }
                }
            }

            $rowsToUpdate = [];
            $newRows = [];

            foreach ($usersData as $userIdRaw => $meals) {
                $userId = (int)$userIdRaw;
                if (!isset($allowedMap[$userId])) {
                    $skipped[] = "利用者ID {$userId} はこの部屋の所属ではないためスキップされました。";
                    continue;
                }

                // サーバー側 allowEdit チェック（buildUsersForJson と同一ロジック）
                // レベル不明（対象ユーザーが存在しない等）は職員扱いにして編集不可へ倒す（fail-closed）
                $isTargetChild = ($userLevelMap[$userId] ?? 0) === 1;
                if (!$canEditAll && $userId !== $loginUid && !($isLoginStaff && $isTargetChild)) {
                    $skipped[] = "利用者ID {$userId} の予約を変更する権限がありません。";
                    continue;
                }

                $targetUserLevel = $userLevelMap[$userId] ?? null;

                foreach ($meals as $mealTypeRaw => $flags) {
                    $mealType = (int)$mealTypeRaw;
                    if (!in_array($mealType, [1,2,3,4], true)) {
                        continue;
                    }

                    $changeFlagRaw = isset($flags['i_change_flag']) ? (int)$flags['i_change_flag'] : null;
                    if ($changeFlagRaw === null) {
                        continue;
                    }
                    $changeFlag = ($changeFlagRaw === 2) ? 0 : $changeFlagRaw;
                    if (!in_array($changeFlag, [0, 1], true)) {
                        $skipped[] = "利用者ID {$userId} の変更フラグが不正なためスキップされました。";
                        continue;
                    }

                    $existingInRoom = $existingByRoom[$userId][$mealType][$roomId] ?? null;
                    $existingForMeal = $existingAny[$userId][$mealType] ?? null;

                    if ($existingInRoom !== null || $existingForMeal !== null) {
                        if ($targetUserLevel !== null && UserRole::isStaffLevel($targetUserLevel) && $changeFlag === 0) {
                            $skipped[] = "利用者ID {$userId} は職員のため、直前編集でのキャンセルはできません。";
                            continue;
                        }

                        // 職員の部屋変更は、直前期間は食数が部屋ごとに変わるため取消と同じく認めない（change-room API と同じ扱い）
                        if ($isLastMinute && $changeFlag === 1 && $targetUserLevel !== null && UserRole::isStaffLevel($targetUserLevel)
                            && $this->hasEffectiveRowInOtherRoom($existingByRoom[$userId][$mealType] ?? [], $roomId)
                        ) {
                            $skipped[] = "利用者ID {$userId} は職員のため、直前期間（14日以内）は部屋の変更はできません。";
                            continue;
                        }

                        $targetRow = $existingInRoom ?? $existingForMeal;
                        // この部屋で食べるなら、他の部屋で有効な同じ食事は取り消す（部屋の移動として扱う）
                        if ($changeFlag === 1) {
                            foreach ($existingByRoom[$userId][$mealType] ?? [] as $otherRoomId => $otherRow) {
                                if ($otherRow === $targetRow || !TIndividualReservationInfoTable::isEffectiveRow($otherRow)) {
                                    continue;
                                }
                                $rowsToUpdate[implode(':', [$userId, $mealType, (int)$otherRoomId])] = [
                                    'row' => $otherRow,
                                    // 直前期間の当初予定（eat_flag）は残す。直前期間外は eat_flag が有効値なので 0 にする
                                    'set' => [
                                        'i_change_flag' => 0,
                                        'c_update_user' => $loginUser?->get('c_user_name') ?? 'system',
                                        'dt_update' => DateTime::now('Asia/Tokyo'),
                                    ] + ($isLastMinute ? [] : ['eat_flag' => 0]),
                                ];
                            }
                        }
                        if ((int)$targetRow->i_change_flag !== $changeFlag
                            || (int)$targetRow->i_id_room !== $roomId
                            || (!$isLastMinute && (int)$targetRow->eat_flag !== $changeFlag)
                        ) {
                            $updateKey = implode(':', [$userId, $mealType, (int)$targetRow->i_id_room]);
                            $rowsToUpdate[$updateKey] = [
                                'row' => $targetRow,
                                'set' => [
                                    'i_change_flag' => $changeFlag,
                                    'i_id_room' => $roomId,
                                    'c_update_user' => $loginUser?->get('c_user_name') ?? 'system',
                                    'dt_update' => DateTime::now('Asia/Tokyo'),
                                // 直前期間外は eat_flag が有効値なので、そろえないと「どの部屋でも食べない」状態になる
                                ] + ($isLastMinute ? [] : ['eat_flag' => $changeFlag]),
                            ];
                            $updated[] = "{$userId}:{$mealType}";
                        }
                    } else {
                        if ($changeFlag === 0) {
                            continue;
                        }
                        $newRows[] = [
                            'i_id_user'          => $userId,
                            'd_reservation_date' => $date,
                            'i_reservation_type' => $mealType,
                            'i_id_room'          => $roomId,
                            'eat_flag'           => $isLastMinute ? 0 : $changeFlag,
                            'i_change_flag'      => $changeFlag,
                            'i_version'          => 1,
                            'c_create_user'      => $loginUser?->get('c_user_name') ?? 'system',
                            'dt_create'          => DateTime::now('Asia/Tokyo'),
                        ];
                        $created[] = "{$userId}:{$mealType}";
                    }
                }
            }

            foreach ($rowsToUpdate as $item) {
                $ok = $this->updateReservationRowWithVersion($reservationTable, $item['row'], $item['set']);
                if (!$ok) {
                    throw new OptimisticLockConflictException();
                }
            }

            if (!empty($newRows)) {
                foreach (array_chunk($newRows, 500) as $chunk) {
                    $entities = $reservationTable->newEntities($chunk);
                    $reservationTable->saveManyOrFail($entities);
                }
            }

            $connection->commit();
        } catch (\Throwable $e) {
            $connection->rollback();
            throw $e;
        }

        return [
            'updated' => $updated,
            'created' => $created,
            'skipped' => $skipped,
        ];
    }

    /**
     * 承認済み保護つきの共通更新（TIndividualReservationInfoTable に集約）。
     *
     * @param array{eat_flag?: int, i_change_flag?: int, i_id_room?: int, c_update_user?: string, dt_update?: \Cake\I18n\DateTime} $updateFields
     * @return bool false = 楽観的ロック競合
     * @throws \App\Exception\ApprovedReservationException 承認済み行を更新しようとした場合
     * @throws \App\Exception\DuplicateMealReservationException 更新で有効になる行が、他の部屋の有効な予約と重なる場合
     */
    private function updateReservationRowWithVersion(Table $reservationTable, object $row, array $updateFields): bool
    {
        return $reservationTable->updateRowWithVersion($row, $updateFields);
    }

    /**
     * 同じ利用者・日付・食事の行のうち、指定の部屋以外に有効な予約があるか。
     *
     * @param array<int, object> $rowsByRoom 部屋ID => 予約行
     */
    private function hasEffectiveRowInOtherRoom(array $rowsByRoom, int $roomId): bool
    {
        foreach ($rowsByRoom as $rid => $row) {
            if ((int)$rid !== $roomId && TIndividualReservationInfoTable::isEffectiveRow($row)) {
                return true;
            }
        }

        return false;
    }
}