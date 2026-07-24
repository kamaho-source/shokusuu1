<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Cache\Cache;
use Cake\I18n\Date;
use Cake\ORM\Table;

class ReservationReportService
{
    private const REPORT_CACHE_SCHEMA_VERSION = 3;

    private function getReportCacheVersion(): int
    {
        $v = Cache::read('reservation_version', 'default');
        $base = (is_int($v) && $v > 0) ? $v : 1;

        return ($base * 10) + self::REPORT_CACHE_SCHEMA_VERSION;
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
        $cacheKey = sprintf('users_by_room_edit:%d:%s:v2', $roomId, $date);
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
            ->select(['i_id_user', 'i_reservation_type', 'eat_flag', 'i_change_flag'])
            ->where([
                'i_id_room' => $roomId,
                'd_reservation_date' => $date,
                'i_id_user IN' => $userIds,
            ])
            ->all()
            ->groupBy('i_id_user')
            ->toArray();

        $useChangeFlagCache = [];
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
                if (!$this->isEffectiveReservation(
                    $date,
                    (int)($r->eat_flag ?? 0),
                    $r->i_change_flag !== null ? (int)$r->i_change_flag : null,
                    $useChangeFlagCache
                )) {
                    continue;
                }
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
        $alias = $reservationTable->getAlias();
        $datePolicy = new ReservationDatePolicy();
        $useChangeFlagCache = [];

        $reservations = $reservationTable->find()
            ->enableAutoFields(false)
            ->select([
                'room_id'      => $alias . '.i_id_room',
                'room_name'    => 'MRoomInfo.c_room_name',
                'd_reservation_date' => $alias . '.d_reservation_date',
                'meal_type'    => $alias . '.i_reservation_type',
                'eat_flag'     => $alias . '.eat_flag',
                'i_change_flag'=> $alias . '.i_change_flag',
                'total_eaters' => $reservationTable->find()->func()->count('*'),
            ])
            ->innerJoinWith('MRoomInfo')
            ->where([
                $alias . '.d_reservation_date >=' => $from,
                $alias . '.d_reservation_date <=' => $to,
            ])
            ->groupBy([
                $alias . '.i_id_room',
                'MRoomInfo.c_room_name',
                $alias . '.d_reservation_date',
                $alias . '.i_reservation_type',
                $alias . '.eat_flag',
                $alias . '.i_change_flag',
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
            $useChangeFlagCache[$date] ??= $datePolicy->shouldUseChangeFlag(new Date($date));
            $effectiveFlag = $useChangeFlagCache[$date]
                ? (int)($reservation['i_change_flag'] ?? 0)
                : (int)($reservation['eat_flag'] ?? 0);
            if ($effectiveFlag !== 1) {
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
        $alias = $reservationTable->getAlias();
        $datePolicy = new ReservationDatePolicy();
        $useChangeFlagCache = [];

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
                'reservation_date' => $alias . '.d_reservation_date',
                'meal_type'        => $alias . '.i_reservation_type',
                'eat_flag'         => $alias . '.eat_flag',
                'i_change_flag'    => $alias . '.i_change_flag',
                'total_eaters'     => $reservationTable->find()->func()->count('*'),
            ])
            ->innerJoinWith('MUserInfo')
            ->where([
                $alias . '.d_reservation_date >=' => $startDate,
                $alias . '.d_reservation_date <'  => $endDateExclusive,
            ])
            ->groupBy([
                'MUserInfo.i_user_rank',
                'MUserInfo.i_user_gender',
                $alias . '.d_reservation_date',
                $alias . '.i_reservation_type',
                $alias . '.eat_flag',
                $alias . '.i_change_flag',
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
            $useChangeFlagCache[$dateKey] ??= $datePolicy->shouldUseChangeFlag(new Date($dateKey));
            $effectiveFlag = $useChangeFlagCache[$dateKey]
                ? (int)($reservation['i_change_flag'] ?? 0)
                : (int)($reservation['eat_flag'] ?? 0);
            if ($effectiveFlag !== 1) {
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

        $final = $this->aggregateDailyMealCounts($reservationTable, $fromDate, $toDate);
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

        $final = $this->aggregateDailyMealCounts($reservationTable, $fromDate, $toDate, $roomIds);
        Cache::write($cacheKey, $final, 'default');

        return $final;
    }

    /**
     * @param list<int>|null $roomIds
     * @return list<array{date: string, morning: int, lunch: int, dinner: int, bento: int, total: int}>
     */
    private function aggregateDailyMealCounts(
        Table $reservationTable,
        string $fromDate,
        string $toDate,
        ?array $roomIds = null
    ): array {
        $conditions = [
            'd_reservation_date >=' => $fromDate,
            'd_reservation_date <=' => $toDate,
        ];
        if ($roomIds !== null && $roomIds !== []) {
            $conditions['i_id_room IN'] = $roomIds;
        }

        $rows = $reservationTable->find()
            ->enableAutoFields(false)
            ->select([
                'date' => 'd_reservation_date',
                'meal_type' => 'i_reservation_type',
                'eat_flag' => 'eat_flag',
                'i_change_flag' => 'i_change_flag',
            ])
            ->where($conditions)
            ->enableHydration(false)
            ->toArray();

        $useChangeFlagCache = [];
        $result = [];
        foreach ($rows as $row) {
            $dateValue = $row['date'] ?? null;
            if ($dateValue instanceof Date) {
                $date = $dateValue->format('Y-m-d');
            } else {
                $date = $this->normalizeDateString($dateValue);
            }
            if ($date === null) {
                continue;
            }
            if (!$this->isEffectiveReservation(
                $date,
                (int)($row['eat_flag'] ?? 0),
                isset($row['i_change_flag']) ? (int)$row['i_change_flag'] : null,
                $useChangeFlagCache
            )) {
                continue;
            }

            $mealType = (int)($row['meal_type'] ?? 0);
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
                    $result[$date]['morning']++;
                    break;
                case 2:
                    $result[$date]['lunch']++;
                    break;
                case 3:
                    $result[$date]['dinner']++;
                    break;
                case 4:
                    $result[$date]['bento']++;
                    break;
                default:
                    continue 2;
            }

            $result[$date]['total']++;
        }

        ksort($result);

        return array_values($result);
    }

    /**
     * @param array<string, bool> $useChangeFlagCache
     */
    private function isEffectiveReservation(
        string $date,
        int $eatFlag,
        ?int $changeFlag,
        array &$useChangeFlagCache
    ): bool {
        $datePolicy = new ReservationDatePolicy();
        $useChangeFlagCache[$date] ??= $datePolicy->shouldUseChangeFlag(new Date($date));
        $effectiveFlag = $useChangeFlagCache[$date]
            ? (int)($changeFlag ?? 0)
            : $eatFlag;

        return $effectiveFlag === 1;
    }

    private function normalizeDateString(\DateTimeInterface|string|int|null $value): ?string
    {
        if ($value instanceof Date) {
            return $value->format('Y-m-d');
        }

        if (is_object($value) && method_exists($value, 'format')) {
            try {
                return (string)$value->format('Y-m-d');
            } catch (\Throwable) {
                // fall through
            }
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
