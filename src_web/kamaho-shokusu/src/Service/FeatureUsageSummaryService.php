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
    /** @var array<string, string> アクション名 → 表示用機能名 */
    private const ACTION_LABELS = [
        'reservation_individual_save' => '予約登録（個人）',
        'reservation_group_save'      => '予約登録（集団）',
        'reservation_toggle'          => '直前予約トグル',
        'reservation_copy'            => '予約コピー',
        'reservation_bulk_add'        => '予約一括登録',
        'reservation_change_edit'     => '予約直前変更',
        'user_login'                  => 'ログイン',
        'user_login_failed'           => 'ログイン失敗',
        'user_logout'                 => 'ログアウト',
        'user_create'                 => 'ユーザー作成',
        'user_update'                 => 'ユーザー更新',
        'user_delete'                 => 'ユーザー削除',
        'user_restore'                => 'ユーザー復元',
        'user_bulk_import'            => 'ユーザー一括取込',
        'actual_meal_save'            => '実食入力',
        'approval_block_leader'       => '承認（ブロック長）',
        'approval_admin'              => '承認（管理者）',
        'approval_rejected'           => '承認却下',
        'audit_export'                => '監査ログCSV出力',
        'room_create'                 => '部屋作成',
        'room_update'                 => '部屋更新',
        'room_delete'                 => '部屋削除',
        'ai_assistant_ask'            => 'AI問い合わせ',
        'ai_assistant_feedback'       => 'AIフィードバック',
    ];

    /** @var array<string, string> カテゴリ → 表示用ラベル */
    public const CATEGORY_LABELS = [
        'reservation'  => '予約',
        'user'         => 'ユーザー管理',
        'actual_meal'  => '実食管理',
        'approval'     => '承認フロー',
        'master'       => 'マスタ管理',
        'system'       => 'システム',
    ];

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
                'label'          => self::ACTION_LABELS[$action] ?? $action,
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
                $topFeature = self::ACTION_LABELS[$action] ?? $action;
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
        $options = [];
        for ($i = 0; $i < 12; $i++) {
            $ym            = date('Y-m', strtotime("-{$i} months"));
            $options[$ym]  = date('Y年n月', (int)strtotime($ym . '-01'));
        }
        return $options;
    }
}
