<?php
declare(strict_types=1);

namespace App\Infrastructure\Query;

use App\Application\Query\ActualMealQueryInterface;
use App\Domain\Service\MealGridWeekRule;
use App\Domain\Service\ReservationDateRule;
use App\Domain\ValueObject\ApprovalStatus;
use App\Domain\ValueObject\MealType;
use App\Infrastructure\Persistence\Table\MUserGroupTable;
use App\Service\RoomMemberOrderService;
use Cake\Chronos\ChronosDate;
use Cake\Datasource\EntityInterface;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Psr\Clock\ClockInterface;

/**
 * ActualMealQueryInterface の CakePHP 実装（m_user_group・m_user_info・t_individual_reservation_info・t_approval_log の読み取り）。
 */
final class CakeActualMealQuery implements ActualMealQueryInterface
{
    public function __construct(private readonly ClockInterface $clock)
    {
    }

    private static function table(string $alias): Table
    {
        return TableRegistry::getTableLocator()->get($alias);
    }

    public function getAdultUsers(int $roomId): array
    {
        $query = self::table('MUserGroup')->find()
            ->enableAutoFields(false)
            ->select([
                'i_id_user' => 'MUserGroup.i_id_user',
                'user_name' => 'MUserInfo.c_user_name',
                'staff_id'  => 'MUserInfo.i_id_staff',
            ])
            ->innerJoin(
                ['MUserInfo' => 'm_user_info'],
                ['MUserInfo.i_id_user = MUserGroup.i_id_user']
            )
            ->where([
                'MUserGroup.i_id_room' => $roomId,
                ...MUserGroupTable::activeCondition(),
                'MUserInfo.i_del_flag' => 0,
            ])
            ->andWhere(['MUserInfo.i_id_staff IS NOT' => null])
            ->andWhere(['MUserInfo.i_id_staff !=' => ''])
            ->enableHydration(false);

        $result = [];
        foreach (RoomMemberOrderService::orderMembers($query)->all() as $row) {
            $result[] = [
                'id'       => (int)$row['i_id_user'],
                'name'     => (string)$row['user_name'],
                'staff_id' => (string)($row['staff_id'] ?? ''),
            ];
        }

        return $result;
    }

    public function getWeekGrid(array $users, string $weekMonday): array
    {
        $dates = MealGridWeekRule::dateRange($weekMonday, 7);
        $meals = MealType::labels(MealType::LABEL_SHORT_NIGHT, false);

        if ($users === []) {
            return ['dates' => $dates, 'meals' => $meals, 'grid' => [], 'versions' => [], 'statuses' => []];
        }

        /**
         * hydration を切った行
         *
         * @var list<array{
         *     i_id_user: int|string, d_reservation_date: \DateTimeInterface|ChronosDate|string|null, i_reservation_type: int|string,
         *     eat_flag: int|string|null, i_change_flag: int|string|null, i_approval_status: int|string|null, i_version: int|string|null
         * }> $rows
         */
        $rows = self::table('TIndividualReservationInfo')->find()
            ->enableAutoFields(false)
            ->select([
                'i_id_user',
                'd_reservation_date',
                'i_reservation_type',
                'eat_flag',
                'i_change_flag',
                'i_approval_status',
                'i_version',
            ])
            ->where([
                'i_id_user IN'          => array_column($users, 'id'),
                'd_reservation_date IN' => $dates,
                'i_reservation_type IN' => [1, 2, 3],
            ])
            ->enableHydration(false)
            ->all()
            ->toArray();

        // (利用者, 日付, 食事区分) => 予約
        $map = [];
        foreach ($rows as $row) {
            $date = self::normalizeDateString($row['d_reservation_date']);
            if ($date === null) {
                continue;
            }
            $map[(int)$row['i_id_user']][$date][(int)$row['i_reservation_type']] = $row;
        }

        $today    = new ChronosDate($this->clock->now());
        $grid     = [];
        $versions = [];
        $statuses = [];
        foreach ($users as $u) {
            $uid = (int)$u['id'];
            $grid[$uid]     = [];
            $versions[$uid] = [];
            $statuses[$uid] = [];
            foreach ($dates as $date) {
                $grid[$uid][$date]     = [];
                $versions[$uid][$date] = [];
                $statuses[$uid][$date] = [];
                foreach (array_keys($meals) as $mealType) {
                    $row = $map[$uid][$date][$mealType] ?? null;
                    if ($row === null) {
                        $grid[$uid][$date][$mealType]     = false;
                        $versions[$uid][$date][$mealType] = 1;
                        $statuses[$uid][$date][$mealType] = ApprovalStatus::PENDING;
                        continue;
                    }
                    $grid[$uid][$date][$mealType] = ReservationDateRule::countedValue(
                        new ChronosDate($date),
                        $row['eat_flag'] === null ? null : (int)$row['eat_flag'],
                        $row['i_change_flag'] === null ? null : (int)$row['i_change_flag'],
                        $today,
                    ) === 1;
                    $versions[$uid][$date][$mealType] = (int)($row['i_version'] ?? 1);
                    $statuses[$uid][$date][$mealType] = (int)($row['i_approval_status'] ?? ApprovalStatus::PENDING);
                }
            }
        }

        return ['dates' => $dates, 'meals' => $meals, 'grid' => $grid, 'versions' => $versions, 'statuses' => $statuses];
    }

    public function findLatestRejection(int $userId, string $dateFrom, string $dateTo): ?array
    {
        $log = self::table('TApprovalLog')->find()
            ->contain(['Approvers'])
            ->where([
                'TApprovalLog.i_id_user'             => $userId,
                'TApprovalLog.i_approval_status'     => ApprovalStatus::REJECTED,
                'TApprovalLog.d_reservation_date >=' => $dateFrom,
                'TApprovalLog.d_reservation_date <=' => $dateTo,
            ])
            ->orderByDesc('TApprovalLog.dt_create')
            ->first();
        if (!$log instanceof EntityInterface) {
            return null;
        }

        $approver = $log->get('approver');
        $created  = $log->get('dt_create');

        return [
            'reason'   => (string)($log->get('c_reject_reason') ?: ''),
            'approver' => $approver instanceof EntityInterface ? (string)($approver->get('c_user_name') ?? '') : '',
            'date'     => $created instanceof \DateTimeInterface ? $created->format('Y-m-d H:i') : '',
        ];
    }

    /** 日付を YYYY-MM-DD にする。読めなければ null。 */
    private static function normalizeDateString(\DateTimeInterface|ChronosDate|string|int|null $value): ?string
    {
        if ($value instanceof \DateTimeInterface || $value instanceof ChronosDate) {
            return $value->format('Y-m-d');
        }
        if (is_string($value) && $value !== '') {
            $ts = strtotime($value);
            if ($ts !== false) {
                return date('Y-m-d', $ts);
            }
        }

        return null;
    }
}
