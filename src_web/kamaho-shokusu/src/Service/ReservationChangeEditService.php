<?php
declare(strict_types=1);

namespace App\Service;

use Cake\I18n\DateTime;
use Cake\ORM\Table;

class ReservationChangeEditService
{
    public function getAllowedRooms($loginUser, ?int $roomId, Table $userGroupTable, Table $roomTable): array
    {
        $loginUid  = $loginUser?->get('i_id_user');

        $allowedRoomsQuery = $userGroupTable->find()
            ->select(['MUserGroup.i_id_room', 'MRoomInfo.c_room_name'])
            ->contain(['MRoomInfo'])
            ->where([
                'MUserGroup.i_id_user'   => $loginUid,
                'MUserGroup.active_flag' => 0,
            ])
            ->enableHydration(false)
            ->all();

        $allowedRooms = [];
        foreach ($allowedRoomsQuery as $row) {
            // enableHydration(false) + contain では m_room_info が null になる場合があるため
            // is_array() で配列であることを確認してからアクセスする
            $roomName = is_array($row['m_room_info'] ?? null)
                ? ($row['m_room_info']['c_room_name'] ?? null)
                : null;
            if ($roomName !== null) {
                $allowedRooms[(int)$row['i_id_room']] = (string)$roomName;
            }
        }

        if (empty($allowedRooms)) {
            $isAdmin = ($loginUser && ($loginUser->get('i_admin') === 1 || (int)$loginUser->get('i_user_level') === 0));
            if ($isAdmin) {
                $allRooms = $roomTable->find()
                    ->select(['i_id_room', 'c_room_name'])
                    ->where(['i_del_flag' => 0])
                    ->all();
                foreach ($allRooms as $r) {
                    $allowedRooms[(int)$r->i_id_room] = (string)$r->c_room_name;
                }
            } elseif ($roomId) {
                $roomExists = $roomTable->exists(['i_id_room' => $roomId]);
                if ($roomExists) {
                    $one = $roomTable->find()
                        ->select(['i_id_room','c_room_name'])
                        ->where(['i_id_room' => $roomId])
                        ->first();
                    if ($one) {
                        $allowedRooms = [(int)$one->i_id_room => (string)$one->c_room_name];
                    }
                }
            }
        }

        return $allowedRooms;
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
                'i_change_flag' => (int)$r->i_change_flag,
            ];
        }

        return [
            'room' => $room,
            'users' => $users,
            'userIdList' => $userIdList,
            'userReservations' => $userReservations,
        ];
    }

    public function buildUsersForJson(array $users, $loginUser): array
    {
        $isAdmin  = ($loginUser && ($loginUser->get('i_admin') === 1 || (int)$loginUser->get('i_user_level') === 0));
        $loginUid = $loginUser?->get('i_id_user');

        $usersForJson = [];
        foreach ($users as $u) {
            $allowEdit = $isAdmin || ($loginUid && (int)$loginUid === (int)$u['id']);
            $usersForJson[] = [
                'id'           => $u['id'],
                'name'         => $u['name'],
                'i_user_level' => $u['i_user_level'],
                'userLevel'    => $u['i_user_level'],
                'isStaff'      => ($u['i_user_level'] === 0),
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
        Table $userTable
    ): array {
        $connection = $reservationTable->getConnection();
        $connection->begin();

        $updated = [];
        $created = [];
        $skipped = [];

        try {
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
                    $existingAny[$uid][$mealType] ??= $row;
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
                        if ($targetUserLevel === 0 && $changeFlag === 0) {
                            $skipped[] = "利用者ID {$userId} は職員のため、直前編集でのキャンセルはできません。";
                            continue;
                        }

                        $targetRow = $existingInRoom ?? $existingForMeal;
                        if ((int)$targetRow->i_change_flag !== $changeFlag || (int)$targetRow->i_id_room !== $roomId) {
                            $updateKey = implode(':', [$userId, $mealType, (int)$targetRow->i_id_room]);
                            $rowsToUpdate[$updateKey] = [
                                'row' => $targetRow,
                                'set' => [
                                    'i_change_flag' => $changeFlag,
                                    'i_id_room' => $roomId,
                                    'c_update_user' => $loginUser?->get('c_user_name') ?? 'system',
                                    'dt_update' => DateTime::now('Asia/Tokyo'),
                                ],
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
                            'eat_flag'           => 0,
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
                    throw new \RuntimeException('optimistic_conflict');
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

    private function updateReservationRowWithVersion(Table $reservationTable, object $row, array $updateFields): bool
    {
        $expectedVersion = (int)($row->i_version ?? 1);
        $set = $updateFields;
        $set['i_version'] = $expectedVersion + 1;

        $dateValue = $row->d_reservation_date;
        if ($dateValue instanceof \DateTimeInterface) {
            $dateValue = $dateValue->format('Y-m-d');
        } else {
            $dateValue = (string)$dateValue;
        }

        $affected = $reservationTable->updateAll(
            $set,
            [
                'i_id_user' => (int)$row->i_id_user,
                'd_reservation_date' => $dateValue,
                'i_reservation_type' => (int)$row->i_reservation_type,
                'i_id_room' => (int)$row->i_id_room,
                'i_version' => $expectedVersion,
            ]
        );

        return $affected === 1;
    }
}
