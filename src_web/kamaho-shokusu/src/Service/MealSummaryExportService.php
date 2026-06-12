<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\TableRegistry;

/**
 * 食事集計エクスポートサービス
 *
 * 指定年度・月の職員別食事回数と料金を集計する。
 */
class MealSummaryExportService
{
    private const RESERVATION_TYPE_MORNING = 1;
    private const RESERVATION_TYPE_LUNCH   = 2;
    private const RESERVATION_TYPE_DINNER  = 3;
    private const RESERVATION_TYPE_BENTO   = 4;
    private const APPROVAL_STATUS_APPROVED = 2;

    /** 未承認プレビュー対象ステータス（差し戻し=3は除外） */
    private const PREVIEW_STATUSES = [0, 1];

    /**
     * 指定年度・月の職員別食事集計データを返す。
     *
     * @param int $year  年度
     * @param int $month 月（1〜12）
     * @return array{
     *   meal_prices: array{morning:int, lunch:int, dinner:int, bento:int},
     *   rows: array{name:string, staff_id:mixed, meal_counts:array, total_price:int}[]
     * }
     */
    public function aggregate(int $year, int $month): array
    {
        $mealPrices = $this->fetchMealPrices($year);
        $users      = $this->fetchStaffUsers();

        $rows = [];
        foreach ($users as $user) {
            $mealCounts    = $this->countMeals($user->i_id_user, $year, $month);
            $mealTotalPrice = $this->calcTotalPrice($mealCounts, $mealPrices);

            $rows[] = [
                'name'        => $user->c_user_name,
                'staff_id'    => $user->i_id_staff,
                'meal_counts' => $mealCounts,
                'total_price' => $mealTotalPrice,
            ];
        }

        return [
            'meal_prices' => $mealPrices,
            'rows'        => $rows,
        ];
    }

    /**
     * 未承認プレビュー用: 未承認(0)・ブロック長承認済(1) のみを集計する。
     * 「職員 × 承認ステータス」の組み合わせで1行ずつ展開し、
     * 単価情報も合わせて返す。食事が0件の行はスキップする。
     *
     * @param int $year  年度
     * @param int $month 月（1〜12）
     * @return array{
     *   meal_prices: array{morning:int, lunch:int, dinner:int, bento:int},
     *   rows: array{name:string, staff_id:mixed, approval_status:int, meal_counts:array, total_price:int}[]
     * }
     */
    public function aggregatePreview(int $year, int $month): array
    {
        $mealPrices = $this->fetchMealPrices($year);
        $users      = $this->fetchStaffUsers();

        $rows = [];
        foreach ($users as $user) {
            $statusBreakdown = $this->countMealsByStatus($user->i_id_user, $year, $month);

            foreach ($statusBreakdown as $status => $counts) {
                // 食事が1件もないステータスはスキップ
                if (array_sum($counts) === 0) {
                    continue;
                }
                $rows[] = [
                    'name'            => $user->c_user_name,
                    'staff_id'        => $user->i_id_staff,
                    'approval_status' => $status,
                    'meal_counts'     => $counts,
                    'total_price'     => $this->calcTotalPrice($counts, $mealPrices),
                ];
            }
        }

        return [
            'meal_prices' => $mealPrices,
            'rows'        => $rows,
        ];
    }

    /**
     * @return array{morning: int, lunch: int, dinner: int, bento: int}
     */
    private function fetchMealPrices(int $year): array
    {
        $table = TableRegistry::getTableLocator()->get('MMealPriceInfo');
        $row   = $table->find()
            ->select(['i_morning_price', 'i_lunch_price', 'i_dinner_price', 'i_bento_price'])
            ->where(['i_fiscal_year' => $year])
            ->first();

        return [
            'morning' => $row ? ($row->i_morning_price ?? 0) : 0,
            'lunch'   => $row ? ($row->i_lunch_price   ?? 0) : 0,
            'dinner'  => $row ? ($row->i_dinner_price  ?? 0) : 0,
            'bento'   => $row ? ($row->i_bento_price   ?? 0) : 0,
        ];
    }

    private function fetchStaffUsers(): array
    {
        $table = TableRegistry::getTableLocator()->get('MUserInfo');
        return $table->find()
            ->select(['i_id_user', 'c_user_name', 'i_id_staff'])
            ->where(['i_id_staff IS NOT' => null, 'i_del_flag' => 0])
            ->all()
            ->toArray();
    }

    /**
     * @return array{morning: int, lunch: int, dinner: int, bento: int}
     */
    private function countMeals(int $userId, int $year, int $month): array
    {
        $table = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $rows  = $table->find()
            ->select(['i_reservation_type', 'eat_flag', 'i_change_flag', 'i_approval_status'])
            ->where([
                'i_id_user'              => $userId,
                'YEAR(d_reservation_date)'  => $year,
                'MONTH(d_reservation_date)' => $month,
                'i_approval_status'      => self::APPROVAL_STATUS_APPROVED,
            ])
            ->toArray();

        $counts = ['bento' => 0, 'morning' => 0, 'lunch' => 0, 'dinner' => 0];

        foreach ($rows as $row) {
            $effectiveFlag = $row->i_change_flag !== null
                ? (int)$row->i_change_flag
                : (int)($row->eat_flag ?? 0);

            if ($effectiveFlag !== 1) {
                continue;
            }

            match ((int)$row->i_reservation_type) {
                self::RESERVATION_TYPE_BENTO   => $counts['bento']++,
                self::RESERVATION_TYPE_MORNING => $counts['morning']++,
                self::RESERVATION_TYPE_LUNCH   => $counts['lunch']++,
                self::RESERVATION_TYPE_DINNER  => $counts['dinner']++,
                default                        => null,
            };
        }

        return $counts;
    }

    /**
     * ステータス別内訳: 各ステータス(0/1)の有効食事件数を返す。
     *
     * @return array<int, array{morning: int, lunch: int, dinner: int, bento: int}>
     */
    private function countMealsByStatus(int $userId, int $year, int $month): array
    {
        $table = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $rows  = $table->find()
            ->select(['i_reservation_type', 'eat_flag', 'i_change_flag', 'i_approval_status'])
            ->where([
                'i_id_user'                 => $userId,
                'YEAR(d_reservation_date)'  => $year,
                'MONTH(d_reservation_date)' => $month,
                'i_approval_status IN'      => self::PREVIEW_STATUSES,
            ])
            ->toArray();

        $breakdown = [
            0 => ['bento' => 0, 'morning' => 0, 'lunch' => 0, 'dinner' => 0],
            1 => ['bento' => 0, 'morning' => 0, 'lunch' => 0, 'dinner' => 0],
        ];

        foreach ($rows as $row) {
            $effectiveFlag = $row->i_change_flag !== null
                ? (int)$row->i_change_flag
                : (int)($row->eat_flag ?? 0);

            if ($effectiveFlag !== 1) {
                continue;
            }

            $status = (int)$row->i_approval_status;
            if (!isset($breakdown[$status])) {
                continue;
            }

            match ((int)$row->i_reservation_type) {
                self::RESERVATION_TYPE_BENTO   => $breakdown[$status]['bento']++,
                self::RESERVATION_TYPE_MORNING => $breakdown[$status]['morning']++,
                self::RESERVATION_TYPE_LUNCH   => $breakdown[$status]['lunch']++,
                self::RESERVATION_TYPE_DINNER  => $breakdown[$status]['dinner']++,
                default                        => null,
            };
        }

        return $breakdown;
    }

    /**
     * @param array{morning: int, lunch: int, dinner: int, bento: int} $counts
     * @param array{morning: int, lunch: int, dinner: int, bento: int} $prices
     */
    private function calcTotalPrice(array $counts, array $prices): int
    {
        return (
            $counts['bento']   * $prices['bento'] +
            $counts['morning'] * $prices['morning'] +
            $counts['lunch']   * $prices['lunch'] +
            $counts['dinner']  * $prices['dinner']
        );
    }
}
