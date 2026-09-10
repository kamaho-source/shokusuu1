<?php
declare(strict_types=1);

namespace App\Service;

use App\Domain\Exception\InvalidInputException;
use Cake\ORM\TableRegistry;

/**
 * 食事集計エクスポートサービス
 *
 * 指定年・月の職員別食事回数と料金を集計する。
 *
 * 年度の定義:
 *   m_meal_price_info.i_fiscal_year は「暦年」（1月〜12月）で運用する。
 *   したがって単価は i_fiscal_year = $year、食数は YEAR(d_reservation_date) = $year で
 *   同じ値を使って突き合わせる。会計年度（4月開始）へ変更する場合は両方の対応付けを見直すこと。
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
     * 指定年・月の職員別食事集計データを返す。
     *
     * @param int $year  暦年
     * @param int $month 月（1〜12）
     * @return array{name: string, staff_id: int|string|null, meal_counts: array, total_price: int}[]
     * @throws \App\Domain\Exception\InvalidInputException 単価が未登録・未設定の場合
     */
    public function aggregate(int $year, int $month): array
    {
        $mealPrices = $this->fetchMealPrices($year);
        $users      = $this->fetchStaffUsers();

        $monthlyData = [];
        foreach ($users as $user) {
            $mealCounts    = $this->countMeals($user->i_id_user, $year, $month);
            $mealTotalPrice = $this->calcTotalPrice($mealCounts, $mealPrices);

            $monthlyData[] = [
                'name'        => $user->c_user_name,
                'staff_id'    => $user->i_id_staff,
                'meal_counts' => $mealCounts,
                'total_price' => $mealTotalPrice,
            ];
        }

        return $monthlyData;
    }

    /**
     * 未承認プレビュー用: 未承認(0)・ブロック長承認済(1) のみを集計する。
     * 「職員 × 承認ステータス」の組み合わせで1行ずつ展開し、
     * 単価情報も合わせて返す。食事が0件の行はスキップする。
     *
     * @param int $year  暦年
     * @param int $month 月（1〜12）
     * @return array{
     *   meal_prices: array{morning:int, lunch:int, dinner:int, bento:int},
     *   rows: array{name:string, staff_id:int|string|null, approval_status:int, meal_counts:array, total_price:int}[]
     * }
     * @throws \App\Domain\Exception\InvalidInputException 単価が未登録・未設定の場合
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
     * 指定年の単価を返す。
     *
     * 単価が引けないまま集計すると全員 0 円の控除表が「正常に」出力されてしまうため、
     * 単価行が無い場合・単価が未設定の場合はエラーとして処理を止める。
     *
     * @return array{morning: int, lunch: int, dinner: int, bento: int}
     * @throws \App\Domain\Exception\InvalidInputException 単価が未登録・未設定の場合
     */
    private function fetchMealPrices(int $year): array
    {
        $table = TableRegistry::getTableLocator()->get('MMealPriceInfo');
        $row   = $table->find()
            ->select(['i_morning_price', 'i_lunch_price', 'i_dinner_price', 'i_bento_price'])
            ->where(['i_fiscal_year' => $year])
            ->orderByAsc('i_id')
            ->first();

        if ($row === null) {
            throw new InvalidInputException(
                sprintf('%d年の食事単価が登録されていません。食数単価一覧から登録してください。', $year)
            );
        }

        $prices = [
            'morning' => $row->i_morning_price,
            'lunch'   => $row->i_lunch_price,
            'dinner'  => $row->i_dinner_price,
            'bento'   => $row->i_bento_price,
        ];

        $missing = array_keys($prices, null, true);
        if (!empty($missing)) {
            $labels = ['morning' => '朝食', 'lunch' => '昼食', 'dinner' => '夕食', 'bento' => '弁当'];
            throw new InvalidInputException(sprintf(
                '%d年の単価が未設定です（%s）。食数単価一覧から登録してください。',
                $year,
                implode('・', array_map(static fn(string $key): string => $labels[$key], $missing))
            ));
        }

        return array_map('intval', $prices);
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
        [$from, $to] = $this->monthRange($year, $month);

        $table = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $rows  = $table->find()
            ->select(['i_reservation_type', 'eat_flag', 'i_change_flag', 'i_approval_status'])
            ->where([
                'i_id_user'               => $userId,
                'd_reservation_date >='   => $from,
                'd_reservation_date <='   => $to,
                'i_approval_status'       => self::APPROVAL_STATUS_APPROVED,
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
        [$from, $to] = $this->monthRange($year, $month);

        $table = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $rows  = $table->find()
            ->select(['i_reservation_type', 'eat_flag', 'i_change_flag', 'i_approval_status'])
            ->where([
                'i_id_user'              => $userId,
                'd_reservation_date >='  => $from,
                'd_reservation_date <='  => $to,
                'i_approval_status IN'   => self::PREVIEW_STATUSES,
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
     * 対象月の日付範囲（月初・月末）を返す。
     *
     * YEAR()/MONTH() ではなく範囲指定で絞ることで、d_reservation_date のインデックスが効き、
     * MySQL 依存の関数も避けられる。
     *
     * @return array{0: string, 1: string} [月初 'YYYY-MM-DD', 月末 'YYYY-MM-DD']
     * @throws \App\Domain\Exception\InvalidInputException 月が 1〜12 の範囲外の場合
     */
    private function monthRange(int $year, int $month): array
    {
        if ($month < 1 || $month > 12) {
            throw new InvalidInputException('月は1〜12で指定してください。');
        }

        $first = sprintf('%04d-%02d-01', $year, $month);

        return [$first, date('Y-m-t', (int)mktime(0, 0, 0, $month, 1, $year))];
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
