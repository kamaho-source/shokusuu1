<?php
declare(strict_types=1);

namespace App\Service;

use Cake\I18n\Date;
use Cake\ORM\Table;

class ReservationQueryService
{
    private ReservationDatePolicy $datePolicy;
    private RoomAccessService $roomAccessService;

    public function __construct(?ReservationDatePolicy $datePolicy = null, ?RoomAccessService $roomAccessService = null)
    {
        $this->datePolicy = $datePolicy ?? new ReservationDatePolicy();
        $this->roomAccessService = $roomAccessService ?? new RoomAccessService();
    }

    public function getAuthorizedRooms(Table $roomTable, Table $userGroupTable, int $userId): array
    {
        return $this->roomAccessService->getAccessibleRooms($roomTable, $userId);
    }

    public function getUsersByRoom(Table $userGroupTable, Table $reservationTable, int $roomId, ?string $date): array
    {
        $users = $userGroupTable->find()
            ->enableAutoFields(false)
            ->select([
                'MUserGroup.i_id_user',
                'MUserGroup.i_id_room',
                'user_name' => 'MUserInfo.c_user_name',
            ])
            ->innerJoin(
                ['MUserInfo' => 'm_user_info'],
                ['MUserInfo.i_id_user = MUserGroup.i_id_user']
            )
            ->where(['MUserGroup.i_id_room' => $roomId, 'MUserGroup.active_flag' => 0])
            ->andWhere(['MUserInfo.i_del_flag' => 0])
            ->enableHydration(false)
            ->toArray();

        $existingReservations = [];
        $useChangeFlag = false;
        if ($date) {
            try {
                $targetDate = new Date($date);
                $useChangeFlag = $this->datePolicy->shouldUseChangeFlag($targetDate);
            } catch (\Throwable $e) {
                $useChangeFlag = false;
            }

            $existingReservations = $reservationTable->find()
                ->select(['i_id_user', 'i_reservation_type', 'eat_flag', 'i_change_flag'])
                ->where([
                    'i_id_room' => $roomId,
                    'd_reservation_date' => $date
                ])
                ->toArray();
        }

        $reservedMap = [];
        foreach ($existingReservations as $reservation) {
            $effective = $useChangeFlag
                ? (int)($reservation->i_change_flag ?? $reservation->eat_flag ?? 0)
                : (int)($reservation->eat_flag ?? 0);
            if ($effective !== 1) {
                continue;
            }
            $reservedMap[(int)$reservation->i_id_user][(int)$reservation->i_reservation_type] = true;
        }

        $usersByRoom = [];
        foreach ($users as $user) {
            $userId = (int)$user['i_id_user'];
            $usersByRoom[] = [
                'id' => $userId,
                'name' => (string)$user['user_name'],
                'morning' => !empty($reservedMap[$userId][1]),
                'noon' => !empty($reservedMap[$userId][2]),
                'night' => !empty($reservedMap[$userId][3]),
                'bento' => !empty($reservedMap[$userId][4]),
            ];
        }

        return $usersByRoom;
    }

    public function getUsersByRoomForBulk(
        Table $userGroupTable,
        Table $reservationTable,
        int $roomId,
        ?string $date,
        int $page = 1,
        int $limit = 100
    ): array
    {
        $baseConditions = [
            'MUserGroup.i_id_room' => $roomId,
            'MUserGroup.active_flag' => 0
        ];

        $total = (int)$userGroupTable->find()
            ->innerJoin(
                ['MUserInfo' => 'm_user_info'],
                ['MUserInfo.i_id_user = MUserGroup.i_id_user']
            )
            ->where($baseConditions)
            ->andWhere(['MUserInfo.i_del_flag' => 0])
            ->count();

        $users = $userGroupTable->find()
            ->enableAutoFields(false)
            ->select([
                'MUserGroup.i_id_user',
                'MUserGroup.i_id_room',
                'user_name' => 'MUserInfo.c_user_name',
                'user_level' => 'MUserInfo.i_user_level',
                'staff_id' => 'MUserInfo.i_id_staff',
            ])
            ->innerJoin(
                ['MUserInfo' => 'm_user_info'],
                ['MUserInfo.i_id_user = MUserGroup.i_id_user']
            )
            ->where($baseConditions)
            ->andWhere(['MUserInfo.i_del_flag' => 0])
            ->limit($limit)
            ->page($page)
            ->enableHydration(false)
            ->all();

        $userData = [];
        foreach ($users as $user) {
            $staffId = $user['staff_id'] ?? null;
            $userData[] = [
                'id' => (int)$user['i_id_user'],
                'name' => (string)$user['user_name'],
                'i_user_level' => (int)($user['user_level'] ?? 0),
                'is_staff' => ($staffId !== null && $staffId !== ''),
            ];
        }

        $reservations = [];
        $otherRoomReservations = [];
        $snapshot = null;
        if (!empty($date) && !empty($userData)) {
            $userIds = array_map(static fn($u) => (int)$u['id'], $userData);
            try {
                $targetDate = new Date($date, 'Asia/Tokyo');
                $today = Date::today('Asia/Tokyo');
                $isLastMinute = $this->datePolicy->isLastMinuteWindow($targetDate, $today, 'Asia/Tokyo');
            } catch (\Throwable $e) {
                $isLastMinute = false;
            }

            $snapshotQuery = $reservationTable->find();
            $snapshotRow = $snapshotQuery
                ->enableAutoFields(false)
                ->select([
                    'max_dt' => $snapshotQuery->func()->max(
                        $snapshotQuery->newExpr('COALESCE(dt_update, dt_create)')
                    )
                ])
                ->where([
                    'd_reservation_date' => $date,
                    'i_id_room' => $roomId,
                ])
                ->first();
            if ($snapshotRow && $snapshotRow->max_dt) {
                $snapshot = (string)$snapshotRow->max_dt;
            }

            $rows = $reservationTable->find()
                ->enableAutoFields(false)
                ->select(['i_id_user', 'i_reservation_type', 'eat_flag', 'i_change_flag', 'i_id_room'])
                ->where([
                    'd_reservation_date' => $date,
                    'i_id_user IN' => $userIds,
                    'i_reservation_type IN' => [1, 2, 3, 4],
                ])
                ->all();

            foreach ($rows as $r) {
                $uid = (int)$r->i_id_user;
                $type = (int)$r->i_reservation_type;
                if ($this->effectiveFlag($r->eat_flag, $r->i_change_flag, $isLastMinute) !== 1) {
                    continue;
                }
                $rid = (int)$r->i_id_room;
                if ($rid === $roomId) {
                    $reservations[$uid][$type] = true;
                } else {
                    $otherRoomReservations[$uid][$type] = $rid;
                }
            }
        }

        return [
            'users' => $userData,
            'reservations' => $reservations,
            'other_room_reservations' => $otherRoomReservations,
            'total' => $total,
            'page' => $page,
            'limit' => $limit,
            'reservation_snapshot' => $snapshot,
        ];
    }

    public function getReservationSnapshots(
        Table $reservationTable,
        int $roomId,
        array $dates
    ): array {
        if (!$roomId || empty($dates)) {
            return [];
        }
        $snapshotQuery = $reservationTable->find();
        $rows = $snapshotQuery
            ->enableAutoFields(false)
            ->select([
                'd_reservation_date',
                'max_dt' => $snapshotQuery->func()->max(
                    $snapshotQuery->newExpr('COALESCE(dt_update, dt_create)')
                )
            ])
            ->where([
                'i_id_room' => $roomId,
                'd_reservation_date IN' => $dates,
            ])
            ->group(['d_reservation_date'])
            ->all();

        $map = [];
        foreach ($rows as $row) {
            $dateKey = (string)$row->d_reservation_date;
            $map[$dateKey] = $row->max_dt ? (string)$row->max_dt : null;
        }
        return $map;
    }

    private function effectiveFlag($eatFlag, $chgFlag, bool $isLastMinute): int
    {
        if ($isLastMinute && $chgFlag !== null) {
            return (int)$chgFlag;
        }
        return (int)($eatFlag ?? 0);
    }

    public function getPersonalReservationData(
        Table $reservationTable,
        Table $roomTable,
        Table $userGroupTable,
        int $userId,
        string $userName,
        string $date
    ): array {
        $reservations = $reservationTable->find()
            ->select(['i_reservation_type'])
            ->where([
                'i_id_user'            => $userId,
                'd_reservation_date'   => $date,
                'eat_flag'             => 1,
            ])
            ->toArray();

        $mealTypes = [1, 2, 3, 4];
        $result = [];
        foreach ($mealTypes as $mealType) {
            $result[(string)$mealType] = false;
        }
        foreach ($reservations as $reservation) {
            $type = $reservation->i_reservation_type;
            $result[(string)$type] = true;
        }

        $authorizedRooms = $this->getAuthorizedRooms($roomTable, $userGroupTable, $userId);

        return [
            'user' => [
                'i_id_user'    => $userId,
                'c_user_name'  => $userName,
            ],
            'reservation'      => $result,
            'authorized_rooms' => $authorizedRooms,
        ];
    }

    public function hasDuplicateReservation(
        Table $reservationTable,
        string $date,
        int $roomId,
        int $mealType
    ): bool {
        return $reservationTable->exists([
            'd_reservation_date' => $date,
            'i_id_room' => $roomId,
            'i_reservation_type' => $mealType,
        ]);
    }
}