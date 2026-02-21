<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Cache\Cache;
use Cake\ORM\Table;

class ReservationReportService
{
    private function getReportCacheVersion(): int
    {
        $v = Cache::read('reservation_version', 'default');
        return (is_int($v) && $v > 0) ? $v : 1;
    }

    public function getMealCounts(Table $reservationTable, string $date): array
    {
        $cacheKey = 'meal_counts:' . $date;
        $cached = Cache::read($cacheKey, 'default');
        if (is_array($cached)) {
            return $cached;
        }
        $rows = $reservationTable->find()
            ->enableAutoFields(false)
            ->select([
                'meal_type' => 'i_reservation_type',
                'count' => $reservationTable->find()->func()->count('*'),
            ])
            ->where([
                'd_reservation_date' => $date,
                'eat_flag' => 1,
                'i_change_flag' => 1,
            ])
            ->groupBy('i_reservation_type')
            ->toArray();
        Cache::write($cacheKey, $rows, 'default');
        return $rows;
    }

    public function getUsersByRoomForEdit(
        Table $userGroupTable,
        Table $reservationTable,
        int $roomId,
        string $date
    ): array {
        $cacheKey = sprintf('users_by_room_edit:%d:%s', $roomId, $date);
        $cached = Cache::read($cacheKey, 'default');
        if (is_array($cached)) {
            return $cached;
        }
        $usersByRoom = $userGroupTable->find()
            ->enableAutoFields(false)
            ->select(['i_id_user', 'i_id_room', 'MUserInfo.c_user_name'])
            ->where(['i_id_room' => $roomId])
            ->contain(['MUserInfo'])
            ->toArray();

        $userIds = collection($usersByRoom)->extract('i_id_user')->toList();

        $reservations = $reservationTable->find()
            ->enableAutoFields(false)
            ->select(['i_id_user', 'i_reservation_type'])
            ->where([
                'i_id_room' => $roomId,
                'd_reservation_date' => $date,
                'i_id_user IN' => $userIds,
            ])
            ->all()
            ->groupBy('i_id_user')
            ->toArray();

        $completeUserInfo = [];
        $mealMap = [
            1 => 'morning',
            2 => 'noon',
            3 => 'night',
            4 => 'bento',
        ];

        foreach ($usersByRoom as $user) {
            $userId = $user->i_id_user;
            $userReservations = $reservations[$userId] ?? [];

            $mealStatus = array_fill_keys(array_values($mealMap), false);
            foreach ($userReservations as $r) {
                $key = $mealMap[$r->i_reservation_type] ?? null;
                if ($key) {
                    $mealStatus[$key] = true;
                }
            }

            $completeUserInfo[] = [
                'id' => $userId,
                'name' => $user->m_user_info->c_user_name ?? '不明なユーザー',
                'meals' => $mealStatus,
            ];
        }

        Cache::write($cacheKey, $completeUserInfo, 'default');
        return $completeUserInfo;
    }

    public function buildExportJson(Table $reservationTable, string $from, string $to): array
    {
        $cacheKey = sprintf('export_json:%s:%s:v%d', $from, $to, $this->getReportCacheVersion());
        $cached = Cache::read($cacheKey, 'default');
        if (is_array($cached)) {
            return $cached;
        }
        $reservations = $reservationTable->find()
            ->enableAutoFields(false)
            ->select([
                'room_id'      => 'TIndividualReservationInfo.i_id_room',
                'room_name'    => 'MRoomInfo.c_room_name',
                'd_reservation_date',
                'meal_type'    => 'TIndividualReservationInfo.i_reservation_type',
                'total_eaters' => $reservationTable->find()->func()->count('*'),
            ])
            ->join([
                'table'      => 'm_room_info',
                'alias'      => 'MRoomInfo',
                'type'       => 'INNER',
                'conditions' => 'MRoomInfo.i_id_room = TIndividualReservationInfo.i_id_room',
            ])
            ->where([
                'TIndividualReservationInfo.eat_flag' => 1,
                'TIndividualReservationInfo.d_reservation_date >=' => $from,
                'TIndividualReservationInfo.d_reservation_date <=' => $to,
            ])
            ->groupBy([
                'TIndividualReservationInfo.i_id_room',
                'MRoomInfo.c_room_name',
                'TIndividualReservationInfo.d_reservation_date',
                'TIndividualReservationInfo.i_reservation_type',
            ])
            ->enableHydration(false)
            ->toArray();

        $result      = ['overall' => [], 'rooms' => []];
        $overallTmp  = [];
        $mealTypeMap = [1 => '朝', 2 => '昼', 3 => '夜', 4 => '弁当'];

        foreach ($reservations as $reservation) {
            $roomName    = $reservation['room_name'];
            $date        = $this->normalizeDateString($reservation['d_reservation_date'] ?? null);
            if ($date === null) {
                continue;
            }
            $mealType = (int)($reservation['meal_type'] ?? 0);
            if (!isset($mealTypeMap[$mealType])) {
                continue;
            }
            $mealLabel   = $mealTypeMap[$mealType];
            $totalEaters = (int)$reservation['total_eaters'];

            if (!isset($result['rooms'][$roomName][$date])) {
                $result['rooms'][$roomName][$date] = ['朝' => 0, '昼' => 0, '夜' => 0, '弁当' => 0];
            }
            $result['rooms'][$roomName][$date][$mealLabel] += $totalEaters;

            if (!isset($overallTmp[$roomName][$date])) {
                $overallTmp[$roomName][$date] = ['朝' => 0, '昼' => 0, '夜' => 0, '弁当' => 0];
            }
            $overallTmp[$roomName][$date][$mealLabel] += $totalEaters;
        }

        foreach ($overallTmp as $roomName => $dates) {
            foreach ($dates as $date => $counts) {
                $result['overall'][] = array_merge(
                    ['部屋名' => $roomName, '日付' => $date],
                    $counts
                );
            }
        }

        Cache::write($cacheKey, $result, 'default');
        return $result;
    }

    public function buildExportJsonRank(
        Table $reservationTable,
        string $startDate,
        string $endDateExclusive,
        string $emptyMsg
    ): array {
        $cacheKey = sprintf('export_rank:%s:%s:v%d', $startDate, $endDateExclusive, $this->getReportCacheVersion());
        $cached = Cache::read($cacheKey, 'default');
        if (is_array($cached)) {
            return $cached;
        }
        $rankNames = [
            1 => '3~5歳',
            2 => '低学年',
            3 => '中学年',
            4 => '高学年',
            5 => '中学生',
            6 => '高校生',
            7 => '大人',
        ];

        $reservations = $reservationTable->find()
            ->enableAutoFields(false)
            ->select([
                'user_rank'        => 'MUserInfo.i_user_rank',
                'gender'           => 'MUserInfo.i_user_gender',
                'reservation_date' => 'TIndividualReservationInfo.d_reservation_date',
                'meal_type'        => 'TIndividualReservationInfo.i_reservation_type',
                'total_eaters'     => $reservationTable->find()->func()->count('*'),
            ])
            ->join([
                [
                    'table'      => 'm_user_info',
                    'alias'      => 'MUserInfo',
                    'type'       => 'INNER',
                    'conditions' => 'MUserInfo.i_id_user = TIndividualReservationInfo.i_id_user',
                ],
            ])
            ->where([
                'TIndividualReservationInfo.i_change_flag' => 1,
                'TIndividualReservationInfo.d_reservation_date >=' => $startDate,
                'TIndividualReservationInfo.d_reservation_date <'  => $endDateExclusive,
            ])
            ->groupBy([
                'MUserInfo.i_user_rank',
                'MUserInfo.i_user_gender',
                'TIndividualReservationInfo.d_reservation_date',
                'TIndividualReservationInfo.i_reservation_type',
            ])
            ->enableHydration(false)
            ->toArray();

        $output = [];
        foreach ($reservations as $reservation) {
            $rankId  = $reservation['user_rank'];
            $gender  = $reservation['gender'] === 1 ? '男子'
                : ($reservation['gender'] === 2 ? '女子' : '不明');
            $rankName = $rankNames[$rankId] ?? '不明';

            $dateKey = $this->normalizeDateString($reservation['reservation_date'] ?? null);
            if ($dateKey === null) {
                continue;
            }
            $key     = $rankId . '_' . $gender . '_' . $dateKey;

            if (!isset($output[$key])) {
                $output[$key] = [
                    'rank_name'      => $rankName,
                    'gender'         => $gender,
                    'reservation_date'=> $dateKey,
                    'breakfast'      => 0,
                    'lunch'          => 0,
                    'dinner'         => 0,
                    'bento'          => 0,
                    'total_eaters'   => 0,
                ];
            }

            $count = $reservation['total_eaters'] ?? 0;
            switch ($reservation['meal_type']) {
                case 1:  $output[$key]['breakfast'] += $count; break;
                case 2:  $output[$key]['lunch']     += $count; break;
                case 3:  $output[$key]['dinner']    += $count; break;
                case 4:  $output[$key]['bento']     += $count; break;
            }
            $output[$key]['total_eaters'] += $count;
        }

        $finalOutput = array_values($output);
        if (empty($finalOutput)) {
            $finalOutput = [];
        }

        Cache::write($cacheKey, $finalOutput, 'default');
        return $finalOutput;
    }

    public function buildAllRoomsMealCounts(Table $reservationTable, string $fromDate, string $toDate): array
    {
        $cacheKey = sprintf('all_rooms_meal:%s:%s:v%d', $fromDate, $toDate, $this->getReportCacheVersion());
        $cached = Cache::read($cacheKey, 'default');
        if (is_array($cached)) {
            return $cached;
        }
        $mealCounts = $reservationTable->find()
            ->enableAutoFields(false)
            ->select([
                'date' => 'd_reservation_date',
                'meal_type' => 'i_reservation_type',
                'count' => $reservationTable->find()->func()->count('*'),
            ])
            ->where([
                'd_reservation_date >=' => $fromDate,
                'd_reservation_date <=' => $toDate,
                'eat_flag' => 1,
            ])
            ->groupBy(['d_reservation_date', 'i_reservation_type'])
            ->orderBy(['d_reservation_date' => 'ASC', 'i_reservation_type' => 'ASC'])
            ->toArray();

        $result = [];
        foreach ($mealCounts as $row) {
            $date = $row->date->format('Y-m-d');
            $mealType = (int)$row->meal_type;
            $count = (int)$row->count;

            if (!isset($result[$date])) {
                $result[$date] = [
                    'date' => $date,
                    'morning' => 0,
                    'lunch' => 0,
                    'dinner' => 0,
                    'bento' => 0,
                    'total' => 0,
                ];
            }

            switch ($mealType) {
                case 1:
                    $result[$date]['morning'] = $count;
                    break;
                case 2:
                    $result[$date]['lunch'] = $count;
                    break;
                case 3:
                    $result[$date]['dinner'] = $count;
                    break;
                case 4:
                    $result[$date]['bento'] = $count;
                    break;
            }

            $result[$date]['total'] += $count;
        }

        $final = array_values($result);
        Cache::write($cacheKey, $final, 'default');
        return $final;
    }

    public function buildRoomMealCounts(
        Table $reservationTable,
        array $roomIds,
        string $fromDate,
        string $toDate
    ): array {
        $cacheKey = sprintf('room_meal:%s:%s:%s:v%d', implode(',', $roomIds), $fromDate, $toDate, $this->getReportCacheVersion());
        $cached = Cache::read($cacheKey, 'default');
        if (is_array($cached)) {
            return $cached;
        }
        $mealCounts = $reservationTable->find()
            ->enableAutoFields(false)
            ->select([
                'date' => 'd_reservation_date',
                'meal_type' => 'i_reservation_type',
                'count' => $reservationTable->find()->func()->count('*'),
            ])
            ->where([
                'i_id_room IN' => $roomIds,
                'd_reservation_date >=' => $fromDate,
                'd_reservation_date <=' => $toDate,
                'eat_flag' => 1,
            ])
            ->groupBy(['d_reservation_date', 'i_reservation_type'])
            ->orderBy(['d_reservation_date' => 'ASC', 'i_reservation_type' => 'ASC'])
            ->toArray();

        $result = [];
        foreach ($mealCounts as $row) {
            $date = $row->date->format('Y-m-d');
            $mealType = (int)$row->meal_type;
            $count = (int)$row->count;

            if (!isset($result[$date])) {
                $result[$date] = [
                    'date' => $date,
                    'morning' => 0,
                    'lunch' => 0,
                    'dinner' => 0,
                    'bento' => 0,
                    'total' => 0,
                ];
            }

            switch ($mealType) {
                case 1:
                    $result[$date]['morning'] = $count;
                    break;
                case 2:
                    $result[$date]['lunch'] = $count;
                    break;
                case 3:
                    $result[$date]['dinner'] = $count;
                    break;
                case 4:
                    $result[$date]['bento'] = $count;
                    break;
            }

            $result[$date]['total'] += $count;
        }

        $final = array_values($result);
        Cache::write($cacheKey, $final, 'default');
        return $final;
    }

    private function normalizeDateString(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }

        if (is_string($value) && $value !== '') {
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return date('Y-m-d', $timestamp);
            }
        }

        return null;
    }
}
