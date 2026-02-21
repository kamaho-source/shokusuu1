<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\Table;

class BulkReservationFormService
{
    public function getRoomsForUser(Table $roomTable, int $userId): array
    {
        $rows = $roomTable->find()
            ->enableAutoFields(false)
            ->select([
                'room_id' => 'MRoomInfo.i_id_room',
                'room_name' => 'MRoomInfo.c_room_name',
            ])
            ->innerJoin(
                ['MUserGroup' => 'm_user_group'],
                ['MUserGroup.i_id_room = MRoomInfo.i_id_room']
            )
            ->where(['MUserGroup.i_id_user' => $userId])
            ->distinct(['MRoomInfo.i_id_room', 'MRoomInfo.c_room_name'])
            ->enableHydration(false)
            ->toArray();

        $rooms = [];
        foreach ($rows as $row) {
            $rooms[(int)$row['room_id']] = (string)$row['room_name'];
        }

        return $rooms;
    }

    public function buildBulkAddData(string $selectedDate, ?string $baseWeekParam): array
    {
        $today = new \DateTimeImmutable('now');
        try {
            $selectedDateObj = new \DateTimeImmutable($selectedDate);
        } catch (\Exception $e) {
            $selectedDateObj = $today;
        }

        $dayOfWeekList = ['月', '火', '水', '木', '金', '土', '日'];
        $startOfWeek = $selectedDateObj->modify('monday this week');
        $activeWeekDate = $startOfWeek->format('Y-m-d');

        try {
            $baseWeek = $baseWeekParam
                ? (new \DateTimeImmutable($baseWeekParam))->modify('monday this week')
                : $startOfWeek;
        } catch (\Exception $e) {
            $baseWeek = $startOfWeek;
        }

        $maxWeek = $baseWeek->modify('+21 days');
        $weekStarts = [
            $baseWeek,
            $baseWeek->modify('+7 days'),
            $baseWeek->modify('+14 days'),
            $baseWeek->modify('+21 days'),
        ];
        $weekStartKeys = array_map(fn($d) => $d->format('Y-m-d'), $weekStarts);
        if (!in_array($activeWeekDate, $weekStartKeys, true)) {
            $activeWeekDate = $baseWeek->format('Y-m-d');
        }

        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $startOfWeek->modify("+{$i} days");
            if ($d >= $today->modify('+15 days')) {
                $days[] = [
                    'date' => $d->format('Y-m-d'),
                    'label' => $dayOfWeekList[(int)$d->format('N') - 1],
                    'is_disabled' => false,
                ];
            }
        }

        return [
            'selectedDateObj' => $selectedDateObj,
            'baseWeek' => $baseWeek,
            'maxWeek' => $maxWeek,
            'weekStarts' => $weekStarts,
            'activeWeekDate' => $activeWeekDate,
            'days' => $days,
        ];
    }

    public function buildBulkChangeEditData(string $selectedDate, ?string $baseWeekParam): array
    {
        $today = new \DateTimeImmutable('now');
        try {
            $selectedDateObj = new \DateTimeImmutable($selectedDate);
        } catch (\Exception $e) {
            $selectedDateObj = $today;
        }

        $dayOfWeekList = ['月', '火', '水', '木', '金', '土', '日'];
        $startOfWeek = $selectedDateObj->modify('monday this week');
        $activeWeekDate = $startOfWeek->format('Y-m-d');

        try {
            $baseWeek = $baseWeekParam
                ? (new \DateTimeImmutable($baseWeekParam))->modify('monday this week')
                : $startOfWeek;
        } catch (\Exception $e) {
            $baseWeek = $startOfWeek;
        }

        $maxWeek = $baseWeek->modify('+21 days');
        $weekStarts = [
            $baseWeek,
            $baseWeek->modify('+7 days'),
            $baseWeek->modify('+14 days'),
            $baseWeek->modify('+21 days'),
        ];
        $weekStartKeys = array_map(fn($d) => $d->format('Y-m-d'), $weekStarts);
        if (!in_array($activeWeekDate, $weekStartKeys, true)) {
            $activeWeekDate = $baseWeek->format('Y-m-d');
        }

        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $startOfWeek->modify("+{$i} days");
            $days[] = [
                'date' => $d->format('Y-m-d'),
                'label' => $dayOfWeekList[(int)$d->format('N') - 1],
                'is_disabled' => ($d < $today),
            ];
        }

        return [
            'selectedDateObj' => $selectedDateObj,
            'baseWeek' => $baseWeek,
            'maxWeek' => $maxWeek,
            'weekStarts' => $weekStarts,
            'activeWeekDate' => $activeWeekDate,
            'days' => $days,
        ];
    }
}
