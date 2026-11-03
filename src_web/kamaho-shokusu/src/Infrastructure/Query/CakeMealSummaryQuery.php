<?php
declare(strict_types=1);

namespace App\Infrastructure\Query;

use App\Application\Query\Dto\StaffMealCount;
use App\Application\Query\MealSummaryQueryInterface;
use App\Domain\Service\ReservationDateRule;
use App\Domain\ValueObject\ApprovalStatus;
use App\Domain\ValueObject\MealType;
use Cake\Chronos\ChronosDate;
use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use Psr\Clock\ClockInterface;

/**
 * MealSummaryQueryInterface の CakePHP 実装（m_user_info・t_individual_reservation_info）。
 */
final class CakeMealSummaryQuery implements MealSummaryQueryInterface
{
    private const APPROVAL_STATUS_APPROVED = ApprovalStatus::ADMIN;

    public function __construct(private readonly ClockInterface $clock)
    {
    }

    /**
     * 控除表で数える予約の承認状態の条件。承認機能が有効なら承認済み(2)だけ、オフなら絞らない。
     *
     * @return array<string, int>
     */
    public static function approvalFilterConditions(): array
    {
        return Configure::read('Features.approval') ? ['i_approval_status' => self::APPROVAL_STATUS_APPROVED] : [];
    }

    public function staffMealCounts(int $year, int $month): array
    {
        $staff = TableRegistry::getTableLocator()->get('MUserInfo')->find()
            ->select(['i_id_user', 'c_user_name', 'i_id_staff', 'i_del_flag'])
            ->where(['i_id_staff IS NOT' => null])
            ->all()
            ->toList();
        if ($staff === []) {
            return [];
        }

        // 日付は YEAR()/MONTH() ではなく範囲で絞る（インデックスが効き、DB 方言にも依存しない）
        $start = sprintf('%04d-%02d-01', $year, $month);
        $end   = (new \DateTimeImmutable($start))->modify('+1 month')->format('Y-m-d');
        $today = new ChronosDate($this->clock->now());
        $columns = [
            MealType::Bento->value     => 'bento',
            MealType::Breakfast->value => 'morning',
            MealType::Lunch->value     => 'lunch',
            MealType::Dinner->value    => 'dinner',
        ];
        $counts = [];
        foreach (TableRegistry::getTableLocator()->get('TIndividualReservationInfo')->find()
            ->select(['i_id_user', 'i_reservation_type', 'd_reservation_date', 'eat_flag', 'i_change_flag'])
            ->where([
                'i_id_user IN'          => array_map(static fn($u): int => (int)$u->i_id_user, $staff),
                'd_reservation_date >=' => $start,
                'd_reservation_date <'  => $end,
            ] + self::approvalFilterConditions())
            ->all() as $row) {
            $column = $columns[(int)$row->i_reservation_type] ?? null;
            $counted = ReservationDateRule::countedValue(
                new ChronosDate($row->d_reservation_date->format('Y-m-d')),
                $row->eat_flag === null ? null : (int)$row->eat_flag,
                $row->i_change_flag === null ? null : (int)$row->i_change_flag,
                $today,
            );
            if ($column !== null && $counted === 1) {
                $counts[(int)$row->i_id_user][$column] = ($counts[(int)$row->i_id_user][$column] ?? 0) + 1;
            }
        }

        $out = [];
        foreach ($staff as $user) {
            $c = $counts[(int)$user->i_id_user] ?? [];
            $mealCounts = ['bento' => $c['bento'] ?? 0, 'morning' => $c['morning'] ?? 0, 'lunch' => $c['lunch'] ?? 0, 'dinner' => $c['dinner'] ?? 0];
            // 削除（退職）済み職員は、その月に実績がある場合のみ控除表に載せる
            if ((int)$user->i_del_flag === 1 && array_sum($mealCounts) === 0) {
                continue;
            }
            $out[] = new StaffMealCount((string)$user->c_user_name, $user->i_id_staff, $mealCounts);
        }

        return $out;
    }
}
