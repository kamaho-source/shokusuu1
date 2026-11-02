<?php
declare(strict_types=1);

namespace App\Infrastructure\Query;

use App\Application\Query\Dto\DailyMealCount;
use App\Application\Query\Dto\MealExport;
use App\Application\Query\Dto\MealTypeCount;
use App\Application\Query\Dto\RankMealCount;
use App\Application\Query\Dto\RoomUserMealStatus;
use App\Application\Query\MealReportQueryInterface;
use App\Domain\Service\ReservationDateRule;
use App\Domain\ValueObject\MealType;
use App\Infrastructure\Cache\ReservationCache;
use App\Infrastructure\Persistence\Table\MUserGroupTable;
use App\Service\RoomMemberOrderService;
use Cake\Cache\Cache;
use Cake\Chronos\ChronosDate;
use Cake\I18n\Date;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Psr\Clock\ClockInterface;

/**
 * MealReportQueryInterface の CakePHP 実装（t_individual_reservation_info を集計する）。
 *
 * キャッシュのキーと中身の形は、以前の ReservationReportService と同じ（リリース前に書かれたキャッシュもそのまま読める）。
 */
final class CakeMealReportQuery implements MealReportQueryInterface
{
    /** レポートのキャッシュの形が変わったら上げる。 */
    private const REPORT_CACHE_SCHEMA_VERSION = 2;

    private const RANK_NAMES = [
        1 => '3~5歳',
        2 => '低学年',
        3 => '中学年',
        4 => '高学年',
        5 => '中学生',
        6 => '高校生',
        7 => '大人',
    ];

    public function __construct(private readonly ClockInterface $clock)
    {
    }

    private static function table(string $alias): Table
    {
        return TableRegistry::getTableLocator()->get($alias);
    }

    private function reportCacheVersion(): int
    {
        return (ReservationCache::version() * 10) + self::REPORT_CACHE_SCHEMA_VERSION;
    }

    /**
     * その日に食べる扱いか（境界日以前は i_change_flag、空なら eat_flag。それより先は eat_flag）。
     */
    private function eats(string $date, ?int $eatFlag, ?int $changeFlag): bool
    {
        $today = new ChronosDate($this->clock->now());

        return ReservationDateRule::countedValue(new ChronosDate($date), $eatFlag, $changeFlag, $today) === 1;
    }

    private static function intOrNull(int|string|bool|null $value): ?int
    {
        return $value === null ? null : (int)$value;
    }

    public function getMealCounts(string $date): array
    {
        $cacheKey = ReservationCache::mealCountsKey($date);
        $cached = Cache::read($cacheKey, 'default');
        if (is_array($cached)) {
            return array_map(MealTypeCount::fromArray(...), $cached);
        }

        $rows = self::table('TIndividualReservationInfo')->find()
            ->enableAutoFields(false)
            ->select([
                'meal_type' => 'i_reservation_type',
                'eat_flag' => 'eat_flag',
                'i_change_flag' => 'i_change_flag',
            ])
            ->where(['d_reservation_date' => $date])
            ->enableHydration(false)
            ->toArray();

        $counts = [];
        foreach ($rows as $row) {
            if (!$this->eats($date, self::intOrNull($row['eat_flag'] ?? null), self::intOrNull($row['i_change_flag'] ?? null))) {
                continue;
            }
            $mealType = (int)$row['meal_type'];
            $counts[$mealType] = ($counts[$mealType] ?? 0) + 1;
        }

        $result = [];
        foreach ($counts as $mealType => $count) {
            $result[] = new MealTypeCount($mealType, $count);
        }

        Cache::write($cacheKey, array_map(static fn(MealTypeCount $c) => $c->toArray(), $result), 'default');

        return $result;
    }

    public function getUsersByRoomForEdit(int $roomId, string $date): array
    {
        // キャッシュしない。1部屋・1日分の軽い読み取りで、更新する経路（直前編集・コピー・
        // ユーザー削除・部屋異動など）すべてで消すのは漏れやすく、古い一覧が返っていたため。
        $usersByRoom = RoomMemberOrderService::orderMembers(self::table('MUserGroup')->find())
            ->enableAutoFields(false)
            ->select(['i_id_user', 'i_id_room', 'MUserInfo.c_user_name'])
            // 異動で抜けた所属(active_flag≠0)と削除済みユーザーは含めない
            ->where(['MUserGroup.i_id_room' => $roomId, ...MUserGroupTable::activeCondition(), 'MUserInfo.i_del_flag' => 0])
            ->contain(['MUserInfo'])
            ->toArray();

        $userIds = collection($usersByRoom)->extract('i_id_user')->toList();
        if ($userIds === []) {
            return [];
        }

        $reservations = self::table('TIndividualReservationInfo')->find()
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

        $result = [];
        foreach ($usersByRoom as $user) {
            $eaten = [];
            foreach ($reservations[$user->i_id_user] ?? [] as $r) {
                if ($this->eats($date, (int)($r->eat_flag ?? 0), self::intOrNull($r->i_change_flag))) {
                    $eaten[(int)$r->i_reservation_type] = true;
                }
            }

            $result[] = new RoomUserMealStatus(
                (int)$user->i_id_user,
                $user->m_user_info->c_user_name ?? '不明なユーザー',
                isset($eaten[MealType::Breakfast->value]),
                isset($eaten[MealType::Lunch->value]),
                isset($eaten[MealType::Dinner->value]),
                isset($eaten[MealType::Bento->value]),
            );
        }

        return $result;
    }

    public function getExportJson(string $from, string $to): MealExport
    {
        $cacheKey = sprintf('export_json:%s:%s:v%d', $from, $to, $this->reportCacheVersion());
        $cached = Cache::read($cacheKey, 'default');
        if (is_array($cached)) {
            return MealExport::fromArray($cached);
        }
        $reservationTable = self::table('TIndividualReservationInfo');
        $alias = $reservationTable->getAlias();

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

        $rooms = [];
        $mealTypeMap = MealType::labels(MealType::LABEL_SHORT_NIGHT);

        foreach ($reservations as $reservation) {
            $roomName = $reservation['room_name'];
            $date = self::normalizeDateString($reservation['d_reservation_date'] ?? null);
            if ($date === null) {
                continue;
            }
            if (!$this->eats($date, self::intOrNull($reservation['eat_flag'] ?? null), self::intOrNull($reservation['i_change_flag'] ?? null))) {
                continue;
            }
            $mealType = (int)($reservation['meal_type'] ?? 0);
            if (!isset($mealTypeMap[$mealType])) {
                continue;
            }
            $rooms[$roomName][$date] ??= array_fill_keys($mealTypeMap, 0);
            $rooms[$roomName][$date][$mealTypeMap[$mealType]] += (int)$reservation['total_eaters'];
        }

        $overall = [];
        foreach ($rooms as $roomName => $dates) {
            foreach ($dates as $date => $counts) {
                $overall[] = array_merge(['部屋名' => $roomName, '日付' => $date], $counts);
            }
        }

        $export = new MealExport($overall, $rooms);
        Cache::write($cacheKey, $export->toArray(), 'default');

        return $export;
    }

    public function getExportRank(string $startDate, string $endDateExclusive): array
    {
        $cacheKey = sprintf('export_rank:%s:%s:v%d', $startDate, $endDateExclusive, $this->reportCacheVersion());
        $cached = Cache::read($cacheKey, 'default');
        if (is_array($cached)) {
            return array_map(RankMealCount::fromArray(...), $cached);
        }
        $reservationTable = self::table('TIndividualReservationInfo');
        $alias = $reservationTable->getAlias();

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
            $rankId = $reservation['user_rank'];
            $gender = match ($reservation['gender']) {
                1 => '男子',
                2 => '女子',
                default => '不明',
            };

            $dateKey = self::normalizeDateString($reservation['reservation_date'] ?? null);
            if ($dateKey === null) {
                continue;
            }
            if (!$this->eats($dateKey, self::intOrNull($reservation['eat_flag'] ?? null), self::intOrNull($reservation['i_change_flag'] ?? null))) {
                continue;
            }
            $key = $rankId . '_' . $gender . '_' . $dateKey;
            $output[$key] ??= [
                'rank_name'        => self::RANK_NAMES[$rankId] ?? '不明',
                'gender'           => $gender,
                'reservation_date' => $dateKey,
                'breakfast'        => 0,
                'lunch'            => 0,
                'dinner'           => 0,
                'bento'            => 0,
                'total_eaters'     => 0,
            ];

            $count = (int)($reservation['total_eaters'] ?? 0);
            $column = match ((int)$reservation['meal_type']) {
                MealType::Breakfast->value => 'breakfast',
                MealType::Lunch->value     => 'lunch',
                MealType::Dinner->value    => 'dinner',
                MealType::Bento->value     => 'bento',
                default                    => null,
            };
            if ($column !== null) {
                $output[$key][$column] += $count;
            }
            $output[$key]['total_eaters'] += $count;
        }

        $rows = array_values($output);
        Cache::write($cacheKey, $rows, 'default');

        return array_map(RankMealCount::fromArray(...), $rows);
    }

    public function getDailyMealCounts(string $fromDate, string $toDate, ?array $roomIds = null): array
    {
        $cacheKey = $roomIds === null
            ? sprintf('all_rooms_meal:%s:%s:v%d', $fromDate, $toDate, $this->reportCacheVersion())
            : sprintf('room_meal:%s:%s:%s:v%d', implode(',', $roomIds), $fromDate, $toDate, $this->reportCacheVersion());
        $cached = Cache::read($cacheKey, 'default');
        if (is_array($cached)) {
            return array_map(DailyMealCount::fromArray(...), $cached);
        }

        $conditions = [
            'd_reservation_date >=' => $fromDate,
            'd_reservation_date <=' => $toDate,
        ];
        if ($roomIds !== null && $roomIds !== []) {
            $conditions['i_id_room IN'] = $roomIds;
        }

        $rows = self::table('TIndividualReservationInfo')->find()
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

        $columns = [
            MealType::Breakfast->value => 'morning',
            MealType::Lunch->value     => 'lunch',
            MealType::Dinner->value    => 'dinner',
            MealType::Bento->value     => 'bento',
        ];
        $result = [];
        foreach ($rows as $row) {
            $date = self::normalizeDateString($row['date'] ?? null);
            if ($date === null) {
                continue;
            }
            if (!$this->eats($date, (int)($row['eat_flag'] ?? 0), self::intOrNull($row['i_change_flag'] ?? null))) {
                continue;
            }
            $column = $columns[(int)($row['meal_type'] ?? 0)] ?? null;
            if ($column === null) {
                continue;
            }
            $result[$date] ??= ['date' => $date, 'morning' => 0, 'lunch' => 0, 'dinner' => 0, 'bento' => 0, 'total' => 0];
            $result[$date][$column]++;
            $result[$date]['total']++;
        }

        ksort($result);
        $result = array_values($result);
        Cache::write($cacheKey, $result, 'default');

        return array_map(DailyMealCount::fromArray(...), $result);
    }

    private static function normalizeDateString(\DateTimeInterface|Date|string|int|null $value): ?string
    {
        if ($value instanceof Date) {
            return $value->format('Y-m-d');
        }

        if (is_object($value) && method_exists($value, 'format')) {
            try {
                return (string)$value->format('Y-m-d');
            } catch (\Throwable) {
                // 下の文字列の解釈へ進む
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
