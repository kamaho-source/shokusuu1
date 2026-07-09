<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\TableRegistry;

/**
 * 統計AI用のコンテキスト（集計統計データ）を構築するサービス。
 *
 * プライバシー方針:
 *   外部AI API へ送信されるため、ここで生成するデータは人数・件数・割合などの
 *   集計値のみとする。個人名・個人単位の予約内容は絶対に含めない。
 */
class AiStatsContextService
{
    /** 集計対象: 過去何日分か */
    private const PAST_DAYS = 28;
    /** 集計対象: 未来何日分か */
    private const FUTURE_DAYS = 7;

    /** @var array<int, string> 食種コード → ラベル */
    private const MEAL_LABELS = [1 => '朝食', 2 => '昼食', 3 => '夕食', 4 => '弁当'];

    /** @var array<int, string> 承認ステータス → ラベル */
    private const APPROVAL_LABELS = [
        ApprovalService::STATUS_PENDING      => '未承認',
        ApprovalService::STATUS_BLOCK_LEADER => 'ブロック長承認済',
        ApprovalService::STATUS_ADMIN        => '管理者承認済',
        ApprovalService::STATUS_REJECTED     => '差し戻し',
    ];

    private FeatureUsageSummaryService $featureUsageSummaryService;

    public function __construct(?FeatureUsageSummaryService $featureUsageSummaryService = null)
    {
        $this->featureUsageSummaryService = $featureUsageSummaryService ?? new FeatureUsageSummaryService();
    }

    /**
     * AIのシステムプロンプトに埋め込む統計コンテキスト（Markdown）を構築する。
     *
     * @return string 集計値のみで構成された統計データ
     */
    public function build(): string
    {
        $dateFrom = date('Y-m-d', strtotime('-' . self::PAST_DAYS . ' days'));
        $dateTo   = date('Y-m-d', strtotime('+' . self::FUTURE_DAYS . ' days'));

        $sections = [
            sprintf(
                "## 統計データ（集計期間: %s 〜 %s、本日: %s）\n※すべて集計値であり個人を特定する情報は含まない。",
                $dateFrom,
                $dateTo,
                date('Y-m-d')
            ),
            $this->buildMealTypeSummary($dateFrom, $dateTo),
            $this->buildRoomSummary($dateFrom, $dateTo),
            $this->buildWeeklyTrend($dateFrom, $dateTo),
            $this->buildEatFlagSummary($dateFrom, $dateTo),
            $this->buildApprovalSummary($dateFrom, $dateTo),
            $this->buildFeatureUsageSummary(),
        ];

        return implode("\n\n", array_filter($sections));
    }

    /**
     * 食種別の予約食数（食べる申告のみ）を集計する。
     */
    private function buildMealTypeSummary(string $dateFrom, string $dateTo): string
    {
        $rows = $this->individualTable()->find()
            ->select([
                'meal_type' => 'i_reservation_type',
                'total'     => 'COUNT(*)',
            ])
            ->where([
                'd_reservation_date >=' => $dateFrom,
                'd_reservation_date <=' => $dateTo,
                'eat_flag'              => 1,
            ])
            ->group(['i_reservation_type'])
            ->disableHydration()
            ->toArray();

        $lines = ["### 食種別の予約食数（食べる申告）"];
        foreach ($rows as $row) {
            $label   = self::MEAL_LABELS[(int)$row['meal_type']] ?? ('食種' . $row['meal_type']);
            $lines[] = sprintf('- %s: %d食', $label, (int)$row['total']);
        }
        if (count($lines) === 1) {
            $lines[] = '- データなし';
        }

        return implode("\n", $lines);
    }

    /**
     * 部屋別の予約食数（食べる申告のみ）を集計する。
     */
    private function buildRoomSummary(string $dateFrom, string $dateTo): string
    {
        $rows = $this->individualTable()->find()
            ->select([
                'room_name' => 'MRoomInfo.c_room_name',
                'total'     => 'COUNT(*)',
            ])
            ->innerJoin(
                ['MRoomInfo' => 'm_room_info'],
                ['MRoomInfo.i_id_room = TIndividualReservationInfo.i_id_room']
            )
            ->where([
                'd_reservation_date >=' => $dateFrom,
                'd_reservation_date <=' => $dateTo,
                'eat_flag'              => 1,
            ])
            ->group(['MRoomInfo.i_id_room', 'MRoomInfo.c_room_name'])
            ->orderDesc('total')
            ->disableHydration()
            ->toArray();

        $lines = ['### 部屋別の予約食数（食べる申告）'];
        foreach ($rows as $row) {
            $lines[] = sprintf('- %s: %d食', (string)$row['room_name'], (int)$row['total']);
        }
        if (count($lines) === 1) {
            $lines[] = '- データなし';
        }

        return implode("\n", $lines);
    }

    /**
     * 週別の予約食数推移を集計する。
     */
    private function buildWeeklyTrend(string $dateFrom, string $dateTo): string
    {
        $rows = $this->individualTable()->find()
            ->select([
                'week_start' => 'DATE_FORMAT(DATE_SUB(d_reservation_date, INTERVAL WEEKDAY(d_reservation_date) DAY), \'%Y-%m-%d\')',
                'total'      => 'COUNT(*)',
            ])
            ->where([
                'd_reservation_date >=' => $dateFrom,
                'd_reservation_date <=' => $dateTo,
                'eat_flag'              => 1,
            ])
            ->group(['week_start'])
            ->orderAsc('week_start')
            ->disableHydration()
            ->toArray();

        $lines = ['### 週別の予約食数推移（各週の月曜開始）'];
        foreach ($rows as $row) {
            $lines[] = sprintf('- %s週: %d食', (string)$row['week_start'], (int)$row['total']);
        }
        if (count($lines) === 1) {
            $lines[] = '- データなし';
        }

        return implode("\n", $lines);
    }

    /**
     * 食べる/食べない申告と直前変更の件数を集計する。
     */
    private function buildEatFlagSummary(string $dateFrom, string $dateTo): string
    {
        $table = $this->individualTable();

        $eatCounts = $table->find()
            ->select(['eat_flag', 'total' => 'COUNT(*)'])
            ->where([
                'd_reservation_date >=' => $dateFrom,
                'd_reservation_date <=' => $dateTo,
            ])
            ->group(['eat_flag'])
            ->disableHydration()
            ->toArray();

        $eat   = 0;
        $noEat = 0;
        foreach ($eatCounts as $row) {
            if ((int)($row['eat_flag'] ?? 0) === 1) {
                $eat = (int)$row['total'];
                continue;
            }
            $noEat += (int)$row['total'];
        }

        $changed = $table->find()
            ->where([
                'd_reservation_date >=' => $dateFrom,
                'd_reservation_date <=' => $dateTo,
                'i_change_flag'         => 1,
            ])
            ->count();

        $total   = $eat + $noEat;
        $eatRate = $total > 0 ? round($eat / $total * 100, 1) : 0.0;

        return implode("\n", [
            '### 申告状況・変更傾向',
            sprintf('- 食べる申告: %d件', $eat),
            sprintf('- 食べない申告: %d件', $noEat),
            sprintf('- 食べる率: %.1f%%', $eatRate),
            sprintf('- 直前変更（変更フラグあり）: %d件', $changed),
        ]);
    }

    /**
     * 承認ステータス別の件数を集計する。
     */
    private function buildApprovalSummary(string $dateFrom, string $dateTo): string
    {
        $rows = $this->individualTable()->find()
            ->select(['status' => 'i_approval_status', 'total' => 'COUNT(*)'])
            ->where([
                'd_reservation_date >=' => $dateFrom,
                'd_reservation_date <=' => $dateTo,
            ])
            ->group(['i_approval_status'])
            ->disableHydration()
            ->toArray();

        $lines = ['### 承認状況（予約レコード単位）'];
        foreach ($rows as $row) {
            $label   = self::APPROVAL_LABELS[(int)$row['status']] ?? ('ステータス' . $row['status']);
            $lines[] = sprintf('- %s: %d件', $label, (int)$row['total']);
        }
        if (count($lines) === 1) {
            $lines[] = '- データなし';
        }

        return implode("\n", $lines);
    }

    /**
     * 当月の機能利用状況（上位10件）を集計する。
     */
    private function buildFeatureUsageSummary(): string
    {
        $summary = $this->featureUsageSummaryService->getSummary(date('Y-m'));

        $lines = [sprintf('### 機能利用状況（%s、操作総数: %d件）', date('Y年n月'), (int)$summary['total_operations'])];
        foreach (array_slice($summary['rows'], 0, 10) as $row) {
            $lines[] = sprintf(
                '- %s（%s）: %d回 / 利用者%d人',
                $row['label'],
                $row['category_label'],
                $row['total'],
                $row['unique_users']
            );
        }
        if (count($lines) === 1) {
            $lines[] = '- データなし';
        }

        return implode("\n", $lines);
    }

    private function individualTable(): \Cake\ORM\Table
    {
        return TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
    }
}
