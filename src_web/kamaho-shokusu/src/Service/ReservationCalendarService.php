<?php
declare(strict_types=1);

namespace App\Service;

use Cake\I18n\Date;
use Cake\ORM\Table;

class ReservationCalendarService
{
    private ReservationDatePolicy $datePolicy;

    public function __construct(?ReservationDatePolicy $datePolicy = null)
    {
        $this->datePolicy = $datePolicy ?? new ReservationDatePolicy();
    }

    public function getUserRoomIds(Table $userGroupTable, int $userId): array
    {
        $userGroups = $userGroupTable->find()
            ->select(['i_id_room'])
            ->where(['i_id_user' => $userId, 'active_flag' => 0])
            ->toArray();

        $roomIds = [];
        foreach ($userGroups as $group) {
            if ($group->i_id_room) {
                $roomIds[] = (int)$group->i_id_room;
            }
        }

        return array_values(array_unique($roomIds));
    }

    public function getPrimaryRoomId(array $roomIds): ?int
    {
        return !empty($roomIds) ? (int)$roomIds[0] : null;
    }

    public function isOfficeUser(Table $userGroupTable, Table $roomTable, int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        return $userGroupTable->find()
            ->innerJoin(
                ['MRoomInfo' => $roomTable->getTable()],
                ['MRoomInfo.i_id_room = MUserGroup.i_id_room']
            )
            ->where([
                'MUserGroup.i_id_user' => $userId,
                'MUserGroup.active_flag' => 0,
                'MRoomInfo.c_room_name LIKE' => '%事務所%',
            ])
            ->count() > 0;
    }

    public function getRoomsForUser(Table $roomTable, array $userRoomIds, bool $isAdmin, bool $isOfficeUser = false): array
    {
        if ($isAdmin) {
            $roomOrder = ['i_id_room' => 'ASC'];
            try {
                $schema = $roomTable->getSchema();
                if (method_exists($schema, 'hasColumn')) {
                    if ($schema->hasColumn('i_sort')) {
                        $roomOrder = ['i_sort' => 'ASC', 'i_id_room' => 'ASC'];
                    } elseif ($schema->hasColumn('display_order')) {
                        $roomOrder = ['display_order' => 'ASC', 'i_id_room' => 'ASC'];
                    } elseif ($schema->hasColumn('i_disp_no')) {
                        $roomOrder = ['i_disp_no' => 'ASC', 'i_id_room' => 'ASC'];
                    } elseif ($schema->hasColumn('c_room_name')) {
                        $roomOrder = ['c_room_name' => 'ASC', 'i_id_room' => 'ASC'];
                    }
                }
            } catch (\Throwable $e) {
                $roomOrder = ['i_id_room' => 'ASC'];
            }

            return $roomTable->find('list', [
                'keyField'   => 'i_id_room',
                'valueField' => 'c_room_name',
            ])
                ->orderBy($roomOrder)
                ->toArray();
        }

        if ($isOfficeUser) {
            if (empty($userRoomIds)) {
                return [];
            }

            return $roomTable->find('list', [
                'keyField' => 'i_id_room',
                'valueField' => 'c_room_name',
            ])
                ->where([
                    'i_id_room IN' => $userRoomIds,
                    'c_room_name LIKE' => '%事務所%',
                ])
                ->orderBy(['c_room_name' => 'ASC', 'i_id_room' => 'ASC'])
                ->toArray();
        }

        $primaryRoomId = $this->getPrimaryRoomId($userRoomIds);
        if ($primaryRoomId === null) {
            return [];
        }

        $room = $roomTable->find()
            ->select(['i_id_room', 'c_room_name'])
            ->where(['i_id_room' => $primaryRoomId])
            ->first();

        return $room ? [$room->i_id_room => $room->c_room_name] : [];
    }

    public function buildMealCountsByDate(
        Table $reservationTable,
        ?array $roomIds = null,
        ?string $startDate = null,
        ?string $endDate = null,
        ?Date $borderDate = null
    ): array {
        $borderDate = $borderDate ?? $this->datePolicy->changeBoundaryDate();

        $query = $reservationTable->find()
            ->select([
                'd_reservation_date',
                'i_reservation_type',
                'eat_flag',
                'i_change_flag',
            ]);

        if ($roomIds && !empty($roomIds)) {
            $query->where(['i_id_room IN' => $roomIds]);
        }
        if ($startDate !== null) {
            $query->where(['d_reservation_date >=' => $startDate]);
        }
        if ($endDate !== null) {
            $query->where(['d_reservation_date <' => $endDate]);
        }

        $rows = $query->toArray();
        $mealDataArray = [];

        foreach ($rows as $r) {
            $dateStr = $r->d_reservation_date->format('Y-m-d');
            $type    = (int)$r->i_reservation_type;

            $effective = ($r->d_reservation_date <= $borderDate)
                ? ($r->i_change_flag !== null ? (int)$r->i_change_flag : (int)($r->eat_flag ?? 0))
                : (int)($r->eat_flag ?? 0);

            if ($effective !== 1) {
                continue;
            }

            if (!isset($mealDataArray[$dateStr])) {
                $mealDataArray[$dateStr] = [1 => 0, 2 => 0, 3 => 0, 4 => 0];
            }
            $mealDataArray[$dateStr][$type]++;
        }

        return $mealDataArray;
    }

    public function buildMyReservationDetails(
        Table $reservationTable,
        int $userId,
        ?string $startDate = null,
        ?string $endDate = null,
        ?Date $borderDate = null
    ): array {
        $borderDate = $borderDate ?? $this->datePolicy->changeBoundaryDate();

        $query = $reservationTable->find()
            ->select([
                'd_reservation_date',
                'i_reservation_type',
                'eat_flag',
                'i_change_flag',
            ])
            ->where(['i_id_user' => $userId]);

        if ($startDate !== null) {
            $query->where(['d_reservation_date >=' => $startDate]);
        }
        if ($endDate !== null) {
            $query->where(['d_reservation_date <' => $endDate]);
        }

        $rows = $query->toArray();
        $mealKeys = [1 => 'breakfast', 2 => 'lunch', 3 => 'dinner', 4 => 'bento'];
        $details = [];

        foreach ($rows as $r) {
            $dateStr = $r->d_reservation_date->format('Y-m-d');
            $key = $mealKeys[$r->i_reservation_type] ?? null;
            if (!$key) {
                continue;
            }

            if (!isset($details[$dateStr])) {
                $details[$dateStr] = [
                    'breakfast' => null,
                    'lunch'     => null,
                    'dinner'    => null,
                    'bento'     => null,
                ];
            }

            $effective = ($r->d_reservation_date <= $borderDate)
                ? ($r->i_change_flag !== null ? (int)$r->i_change_flag : (int)($r->eat_flag ?? 0))
                : (int)($r->eat_flag ?? 0);

            $details[$dateStr][$key] = $effective;
        }

        return $details;
    }

    public function buildMyReservationDates(array $details): array
    {
        $dates = [];
        foreach ($details as $date => $meals) {
            if (in_array(1, $meals, true)) {
                $dates[] = $date;
            }
        }
        sort($dates);
        return $dates;
    }

    public function buildCalendarEvents(
        array $mealDataArray,
        array $myReservationDetails,
        Date $startDate,
        Date $endDate
    ): array {
        $myReservationDates = $this->buildMyReservationDates($myReservationDetails);
        $events = [];

        $iconFn = function ($v) {
            if ($v === null) {
                return '×';
            }
            return $v ? '⚪︎' : '×';
        };

        foreach ($myReservationDates as $reservedDate) {
            $detail = $myReservationDetails[$reservedDate] ?? [];
            $title = sprintf(
                '朝:%s 昼:%s 夜:%s 弁:%s',
                $iconFn($detail['breakfast'] ?? null),
                $iconFn($detail['lunch']     ?? null),
                $iconFn($detail['dinner']    ?? null),
                $iconFn($detail['bento']     ?? null)
            );
            $events[] = [
                'title' => $title,
                'start' => $reservedDate,
                'allDay' => true,
                'backgroundColor' => '#28a745',
                'borderColor' => '#28a745',
                'textColor' => 'white',
                'extendedProps' => ['displayOrder' => -2],
            ];
        }

        $mealTypes = ['1' => '朝', '2' => '昼', '3' => '夜', '4' => '弁'];
        foreach ($mealDataArray as $date => $meals) {
            foreach ($mealTypes as $type => $name) {
                if (!empty($meals[$type])) {
                    $events[] = [
                        'title' => "{$name}: {$meals[$type]}人",
                        'start' => $date,
                        'allDay' => true,
                        'extendedProps' => ['displayOrder' => (int)$type],
                    ];
                }
            }
        }

        $dateCursor = $startDate;
        while ($dateCursor < $endDate) {
            $dateStr = $dateCursor->format('Y-m-d');
            if (!in_array($dateStr, $myReservationDates, true)) {
                $events[] = [
                    'title' => '未予約',
                    'start' => $dateStr,
                    'allDay' => true,
                    'backgroundColor' => '#fd7e14',
                    'borderColor' => '#fd7e14',
                    'textColor' => 'white',
                    'extendedProps' => ['displayOrder' => -10],
                ];
            }
            $dateCursor = $dateCursor->addDays(1);
        }

        return $events;
    }

    public function buildTotalEvents(Table $reservationTable, ?Date $borderDate = null): array
    {
        $borderDate = $borderDate ?? $this->datePolicy->changeBoundaryDate();

        $rows = $reservationTable->find()
            ->select(['d_reservation_date', 'eat_flag', 'i_change_flag'])
            ->toArray();

        $dateCounts = [];
        foreach ($rows as $r) {
            $dateStr = $r->d_reservation_date->format('Y-m-d');
            $effective = ($r->d_reservation_date <= $borderDate)
                ? ($r->i_change_flag !== null ? (int)$r->i_change_flag : (int)($r->eat_flag ?? 0))
                : (int)($r->eat_flag ?? 0);
            if ($effective === 1) {
                $dateCounts[$dateStr] = ($dateCounts[$dateStr] ?? 0) + 1;
            }
        }

        $events = [];
        foreach ($dateCounts as $date => $count) {
            $events[] = [
                'title'  => '合計食数: ' . $count,
                'start'  => $date,
                'allDay' => true,
            ];
        }

        return $events;
    }

    /**
     * ログインユーザーの職員情報（名前・職員ID・管理者フラグ）を返す。
     *
     * @param Table $userGroupTable MUserGroup テーブル
     * @param int   $userId         ログインユーザーID
     * @return array
     */
    public function getStaffUserInfo(Table $userGroupTable, int $userId): array
    {
        return $userGroupTable->find()
            ->enableAutoFields(false)
            ->select([
                'user_name' => 'MUserInfo.c_user_name',
                'staff_id'  => 'MUserInfo.i_id_staff',
                'is_admin'  => 'MUserInfo.i_admin',
            ])
            ->innerJoin(
                ['MUserInfo' => 'm_user_info'],
                ['MUserInfo.i_id_user = MUserGroup.i_id_user']
            )
            ->where(['MUserGroup.i_id_user' => $userId])
            ->enableHydration(false)
            ->toArray();
    }

    /**
     * 職員用4週間グリッド: ユーザー×部屋×日付×食事種別の予約状況を返す。
     *
     * @param Table  $reservationTable TIndividualReservationInfo
     * @param array  $userIds          対象ユーザーIDの配列
     * @param array  $roomIds          対象部屋IDの配列
     * @param string $startDate        YYYY-MM-DD
     * @param string $endDate          YYYY-MM-DD (inclusive)
     * @return array [userId][roomId][date][mealType(1-4)] = 0|1
     */
    public function buildFourWeekGrid(
        Table $reservationTable,
        array $userIds,
        array $roomIds,
        string $startDate,
        string $endDate
    ): array {
        if (empty($userIds) || empty($roomIds)) {
            return [];
        }

        $borderDate = $this->datePolicy->changeBoundaryDate();

        $rows = $reservationTable->find()
            ->select([
                'i_id_user',
                'i_id_room',
                'd_reservation_date',
                'i_reservation_type',
                'eat_flag',
                'i_change_flag',
            ])
            ->where([
                'i_id_user IN'           => $userIds,
                'i_id_room IN'           => $roomIds,
                'd_reservation_date >='  => $startDate,
                'd_reservation_date <='  => $endDate,
            ])
            ->enableHydration(false)
            ->toArray();

        $grid = [];
        foreach ($rows as $r) {
            $uid      = (int)$r['i_id_user'];
            $rid      = (int)$r['i_id_room'];
            $dateObj  = $r['d_reservation_date'];
            $dateStr  = is_string($dateObj) ? substr($dateObj, 0, 10) : $dateObj->format('Y-m-d');
            $type     = (int)$r['i_reservation_type'];
            $dateObjForCmp = $dateObj instanceof \DateTimeInterface
                ? $dateObj
                : new \DateTimeImmutable($dateStr);

            $effective = ($dateObjForCmp <= $borderDate)
                ? ($r['i_change_flag'] !== null ? (int)$r['i_change_flag'] : (int)($r['eat_flag'] ?? 0))
                : (int)($r['eat_flag'] ?? 0);

            $grid[$uid][$rid][$dateStr][$type] = $effective;
        }

        return $grid;
    }

    /**
     * 部屋に所属する職員ユーザー一覧を返す。
     *
     * @param Table    $userGroupTable  MUserGroup
     * @param Table    $userInfoTable   MUserInfo
     * @param int[]    $roomIds         対象部屋ID配列（空=全部屋）
     * @param bool     $staffOnly       trueなら職員のみ（i_user_level=0）
     * @return array   [{user_id, user_name, staff_id, room_ids[]}]
     */
    public function getUsersInRooms(
        Table $userGroupTable,
        Table $userInfoTable,
        array $roomIds,
        bool $staffOnly = false
    ): array {
        $query = $userGroupTable->find()
            ->enableAutoFields(false)
            ->select([
                'user_id'   => 'MUserGroup.i_id_user',
                'room_id'   => 'MUserGroup.i_id_room',
                'user_name' => 'MUserInfo.c_user_name',
                'staff_id'  => 'MUserInfo.i_id_staff',
                'user_level'=> 'MUserInfo.i_user_level',
            ])
            ->innerJoin(
                ['MUserInfo' => $userInfoTable->getTable()],
                ['MUserInfo.i_id_user = MUserGroup.i_id_user', 'MUserInfo.i_del_flag' => 0]
            )
            ->where(['MUserGroup.active_flag' => 0]);

        if (!empty($roomIds)) {
            $query->where(['MUserGroup.i_id_room IN' => $roomIds]);
        }

        if ($staffOnly) {
            $query->where(['MUserInfo.i_user_level' => 0]);
        }

        $query->orderBy(['MUserInfo.c_user_name' => 'ASC', 'MUserGroup.i_id_room' => 'ASC'])
              ->enableHydration(false);

        $rows = $query->toArray();

        // user_id をキーに room_ids を集約
        $byUser = [];
        foreach ($rows as $r) {
            $uid = (int)$r['user_id'];
            if (!isset($byUser[$uid])) {
                $byUser[$uid] = [
                    'user_id'    => $uid,
                    'user_name'  => (string)$r['user_name'],
                    'staff_id'   => (string)($r['staff_id'] ?? ''),
                    'user_level' => (int)$r['user_level'],
                    'room_ids'   => [],
                ];
            }
            $byUser[$uid]['room_ids'][] = (int)$r['room_id'];
        }

        return array_values($byUser);
    }

    /**
     * 職員用4週間グリッドの行データを構築する。
     *
     * @param Table  $userGroupTable
     * @param Table  $userInfoTable
     * @param Table  $roomTable
     * @param string $viewMode       'individual'|'room'|'all'
     * @param int[]  $targetUserIds  個人モード時の対象ユーザーID配列
     * @param int[]  $targetRoomIds  部屋モード時の対象部屋ID配列（全部屋=all部屋ID配列）
     * @return array [{room_id, room_name, user_id, user_name, staff_id}]
     */
    public function buildStaffGridRows(
        Table $userGroupTable,
        Table $userInfoTable,
        Table $roomTable,
        string $viewMode,
        array $targetUserIds,
        array $targetRoomIds
    ): array {
        $whereUser = empty($targetUserIds) ? [] : ['MUserGroup.i_id_user IN' => $targetUserIds];
        $whereRoom = empty($targetRoomIds) ? [] : ['MUserGroup.i_id_room IN' => $targetRoomIds];

        $query = $userGroupTable->find()
            ->enableAutoFields(false)
            ->select([
                'user_id'    => 'MUserGroup.i_id_user',
                'room_id'    => 'MUserGroup.i_id_room',
                'user_name'  => 'MUserInfo.c_user_name',
                'staff_id'   => 'MUserInfo.i_id_staff',
                'room_name'  => 'MRoomInfo.c_room_name',
                'room_order' => 'MRoomInfo.i_disp_no',
            ])
            ->innerJoin(
                ['MUserInfo' => $userInfoTable->getTable()],
                ['MUserInfo.i_id_user = MUserGroup.i_id_user', 'MUserInfo.i_del_flag' => 0]
            )
            ->innerJoin(
                ['MRoomInfo' => $roomTable->getTable()],
                ['MRoomInfo.i_id_room = MUserGroup.i_id_room', 'MRoomInfo.i_del_flg' => 0]
            )
            ->where(['MUserGroup.active_flag' => 0])
            ->where($whereUser)
            ->where($whereRoom)
            ->orderBy([
                'MRoomInfo.i_disp_no'  => 'ASC',
                'MRoomInfo.i_id_room'  => 'ASC',
                'MUserInfo.c_user_name' => 'ASC',
            ])
            ->enableHydration(false);

        $rows = $query->toArray();

        return array_map(static fn(array $r): array => [
            'room_id'   => (int)$r['room_id'],
            'room_name' => (string)$r['room_name'],
            'user_id'   => (int)$r['user_id'],
            'user_name' => (string)$r['user_name'],
            'staff_id'  => (string)($r['staff_id'] ?? ''),
        ], $rows);
    }

    /**
     * 削除されていない全部屋のIDを返す。
     *
     * @param Table $roomTable MRoomInfo テーブル
     * @return int[]
     */
    public function getAllActiveRoomIds(Table $roomTable): array
    {
        $rows = $roomTable->find()
            ->select(['i_id_room'])
            ->where(['i_del_flg' => 0])
            ->enableHydration(false)
            ->toArray();

        return array_column($rows, 'i_id_room');
    }

    /**
     * 指定したID配列に一致する削除済みでない部屋を [id => name] の連想配列で返す。
     *
     * @param Table  $roomTable MRoomInfo テーブル
     * @param int[]  $roomIds   対象部屋IDの配列
     * @return array<int, string>
     */
    public function getRoomsByIds(Table $roomTable, array $roomIds): array
    {
        if (empty($roomIds)) {
            return [];
        }

        return $roomTable->find()
            ->where(['i_id_room IN' => $roomIds, 'i_del_flg' => 0])
            ->orderAsc('i_disp_no')
            ->all()
            ->combine('i_id_room', 'c_room_name')
            ->toArray();
    }
}