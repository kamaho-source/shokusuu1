<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\TableRegistry;

/**
 * 機能使用頻度集計サービス
 *
 * t_audit_log を集計し、機能ごとの使用回数・ユニークユーザー数・最終利用日を返す。
 * システム管理者専用ダッシュボードで利用する。
 */
class FeatureUsageSummaryService
{
    /** @var array<string, string> カテゴリ → 表示用ラベル（監査ログと共通） */
    public const CATEGORY_LABELS = AuditLogService::CATEGORY_LABELS;

    /**
     * 機能使用頻度を集計して返す。
     *
     * @param string $yearMonth 対象月 (YYYY-MM)
     * @param string|null $category カテゴリ絞り込み（null = 全件）
     * @return array{rows: list<array{action: string, label: string, category: string, category_label: string, total: int, unique_users: int, last_used: string}>, total_operations: int, top_feature: string}
     */
    public function getSummary(string $yearMonth, ?string $category = null): array
    {
        $table    = TableRegistry::getTableLocator()->get('TAuditLog');
        $dateFrom = $yearMonth . '-01 00:00:00';
        $dateTo   = date('Y-m-t', (int)strtotime($yearMonth . '-01')) . ' 23:59:59';

        $query = $table->find()
            ->select([
                'action'       => 'c_action',
                'category'     => 'c_category',
                'total'        => 'COUNT(*)',
                'unique_users' => 'COUNT(DISTINCT i_actor_user_id)',
                'last_used'    => 'MAX(dt_create)',
            ])
            ->where([
                'dt_create >=' => $dateFrom,
                'dt_create <=' => $dateTo,
                'i_result'     => 1,
            ])
            ->group(['c_action', 'c_category'])
            ->orderBy(['total' => 'DESC'])
            ->disableHydration();

        if ($category !== null && $category !== '') {
            $query->andWhere(['c_category' => $category]);
        }

        $rows            = [];
        $totalOperations = 0;
        $topFeature      = '';
        $maxCount        = 0;

        foreach ($query->toArray() as $row) {
            $action = (string)($row['action'] ?? '');
            $cat    = (string)($row['category'] ?? '');
            $total  = (int)($row['total'] ?? 0);

            $rows[] = [
                'action'         => $action,
                'label'          => AuditLogService::actionLabel($action),
                'category'       => $cat,
                'category_label' => self::CATEGORY_LABELS[$cat] ?? $cat,
                'total'          => $total,
                'unique_users'   => (int)($row['unique_users'] ?? 0),
                'last_used'      => $row['last_used'] !== null
                    ? substr((string)$row['last_used'], 0, 10)
                    : '-',
            ];

            $totalOperations += $total;

            if ($total > $maxCount) {
                $maxCount   = $total;
                $topFeature = AuditLogService::actionLabel($action);
            }
        }

        return [
            'rows'             => $rows,
            'total_operations' => $totalOperations,
            'top_feature'      => $topFeature,
        ];
    }

    /**
     * 時間帯別使用頻度を集計して返す。
     *
     * @param string $yearMonth 対象月 (YYYY-MM)
     * @param string|null $category カテゴリ絞り込み（null = 全件）
     * @return array{hours: list<array{hour: int, label: string, total: int}>, peak_hour: int|null, peak_total: int}
     * @throws \Cake\Database\Exception\DatabaseException DBクエリ失敗時
     */
    public function getHourlyDistribution(string $yearMonth, ?string $category = null): array
    {
        $table    = TableRegistry::getTableLocator()->get('TAuditLog');
        $dateFrom = $yearMonth . '-01 00:00:00';
        $dateTo   = date('Y-m-t', (int)strtotime($yearMonth . '-01')) . ' 23:59:59';

        $query = $table->find()
            ->select([
                'hour'  => 'HOUR(dt_create)',
                'total' => 'COUNT(*)',
            ])
            ->where([
                'dt_create >=' => $dateFrom,
                'dt_create <=' => $dateTo,
                'i_result'     => 1,
            ])
            ->group('HOUR(dt_create)')
            ->orderBy('HOUR(dt_create)')
            ->disableHydration();

        if ($category !== null && $category !== '') {
            $query->andWhere(['c_category' => $category]);
        }

        $rawByHour = [];
        foreach ($query->toArray() as $row) {
            $rawByHour[(int)$row['hour']] = (int)$row['total'];
        }

        $hours     = [];
        $peakHour  = null;
        $peakTotal = 0;

        for ($h = 0; $h < 24; $h++) {
            $total = $rawByHour[$h] ?? 0;
            $hours[] = [
                'hour'  => $h,
                'label' => sprintf('%02d:00', $h),
                'total' => $total,
            ];
            if ($total > $peakTotal) {
                $peakTotal = $total;
                $peakHour  = $h;
            }
        }

        return [
            'hours'      => $hours,
            'peak_hour'  => $peakHour,
            'peak_total' => $peakTotal,
        ];
    }

    /**
     * 選択可能な月一覧（過去12ヶ月）を返す。
     *
     * @return array<string, string>
     */
    public function getMonthOptions(): array
    {
        // 月末（29〜31日）に「-N months」すると存在しない日が翌月へ繰り上がり、月が重複するので月初を起点にする
        $firstOfMonth = new \DateTimeImmutable('first day of this month');
        $options = [];
        for ($i = 0; $i < 12; $i++) {
            $month                        = $firstOfMonth->modify("-{$i} months");
            $options[$month->format('Y-m')] = $month->format('Y年n月');
        }
        return $options;
    }
}
