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
                ? (int)$r->i_change_flag
                : (int)$r->eat_flag;

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
                ? (int)$r->i_change_flag
                : (int)$r->eat_flag;

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
                ? (int)$r->i_change_flag
                : (int)$r->eat_flag;
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
}