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
        $today = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo'));
        $base  = $this->buildWeekBase($selectedDate, $baseWeekParam, $today);

        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $base['startOfWeek']->modify("+{$i} days");
            if ($d >= $today->modify('+15 days')) {
                $days[] = [
                    'date'        => $d->format('Y-m-d'),
                    'label'       => $base['dayOfWeekList'][(int)$d->format('N') - 1],
                    'is_disabled' => false,
                ];
            }
        }

        return array_merge($base, ['days' => $days]);
    }

    public function buildBulkChangeEditData(string $selectedDate, ?string $baseWeekParam): array
    {
        $today = new \DateTimeImmutable('today', new \DateTimeZone('Asia/Tokyo'));
        $base  = $this->buildWeekBase($selectedDate, $baseWeekParam, $today);

        $days = [];
        for ($i = 0; $i < 7; $i++) {
            $d = $base['startOfWeek']->modify("+{$i} days");
            $days[] = [
                'date'        => $d->format('Y-m-d'),
                'label'       => $base['dayOfWeekList'][(int)$d->format('N') - 1],
                'is_disabled' => ($d < $today),
            ];
        }

        return array_merge($base, ['days' => $days]);
    }

    /**
     * 両メソッドで共通する週データ（baseWeek, weekStarts, activeWeekDate など）を構築する。
     *
     * @param string                  $selectedDate   選択日文字列
     * @param string|null             $baseWeekParam  週ナビ基準日文字列
     * @param \DateTimeImmutable      $today          基準となる今日
     * @return array<string, mixed>
     */
    private function buildWeekBase(string $selectedDate, ?string $baseWeekParam, \DateTimeImmutable $today): array
    {
        try {
            $selectedDateObj = new \DateTimeImmutable($selectedDate, new \DateTimeZone('Asia/Tokyo'));
        } catch (\Exception $e) {
            $selectedDateObj = $today;
        }

        $dayOfWeekList = ['月', '火', '水', '木', '金', '土', '日'];
        $startOfWeek   = $selectedDateObj->modify('monday this week');
        $activeWeekDate = $startOfWeek->format('Y-m-d');

        try {
            $baseWeek = $baseWeekParam
                ? (new \DateTimeImmutable($baseWeekParam, new \DateTimeZone('Asia/Tokyo')))->modify('monday this week')
                : $startOfWeek;
        } catch (\Exception $e) {
            $baseWeek = $startOfWeek;
        }

        $maxWeek    = $baseWeek->modify('+21 days');
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

        return [
            'selectedDateObj' => $selectedDateObj,
            'baseWeek'        => $baseWeek,
            'maxWeek'         => $maxWeek,
            'weekStarts'      => $weekStarts,
            'activeWeekDate'  => $activeWeekDate,
            'startOfWeek'     => $startOfWeek,
            'dayOfWeekList'   => $dayOfWeekList,
        ];
    }
}
