<?php
declare(strict_types=1);

namespace App\Service;

use App\Infrastructure\AI\UserTokenizer;
use Cake\ORM\TableRegistry;

/**
 * 統計AI用のコンテキスト（集計統計データ）を構築するサービス。
 *
 * プライバシー方針:
 *   外部AI API へ送信されるため、ここで生成するデータは人数・件数・割合などの
 *   集計値のみとする。個人名・個人単位の予約内容は絶対に含めない。
 */
final class AiStatsContextService
{
    /** 集計対象: 過去何日分か */
    private const PAST_DAYS = 28;
    /** 集計対象: 未来何日分か */
    private const FUTURE_DAYS = 7;
    /** 利用者別サマリの出力上限（コンテキスト肥大化防止） */
    private const USER_SUMMARY_LIMIT = 50;

    /** @var array<int, string> 食種コード → ラベル */
    private const MEAL_LABELS = [1 => '朝食', 2 => '昼食', 3 => '夕食', 4 => '弁当'];

    /** i_user_level: 子供（施設利用児童） */
    private const LEVEL_CHILD = 1;
    /** i_user_level: 職員（大人） */
    private const LEVEL_STAFF = 0;

    /** @var array<int, string> 承認ステータス → ラベル */
    private const APPROVAL_LABELS = [
        ApprovalService::STATUS_PENDING      => '未承認',
        ApprovalService::STATUS_BLOCK_LEADER => 'ブロック長承認済',
        ApprovalService::STATUS_ADMIN        => '管理者承認済',
        ApprovalService::STATUS_REJECTED     => '差し戻し',
    ];

    public function __construct(
        private readonly FeatureUsageSummaryService $featureUsageSummaryService,
        private readonly UserTokenizer $userTokenizer,
        private readonly RoomUsageService $roomUsageService,
    ) {
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
            $this->buildRoomUsageRateSummary($dateFrom, $dateTo),
            $this->buildStaffInputSummary($dateFrom, $dateTo),
            $this->buildUserSummary($dateFrom, $dateTo),
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
     * 子供（児童）・大人（職員）別の部屋使用率を構築する。
     *
     * 使用率の定義は既存の「部屋使用率」画面（RoomUsageService）と同一:
     * 食べた回数 ÷（在籍人数 × 日数 × 食種数）× 100。
     */
    private function buildRoomUsageRateSummary(string $dateFrom, string $dateTo): string
    {
        $groups = [
            '子供（児童）' => self::LEVEL_CHILD,
            '大人（職員）' => self::LEVEL_STAFF,
        ];

        $lines = [
            '### 部屋別の使用率（子供・大人別）',
            '使用率 = 食べた回数 ÷（在籍人数 × 日数 × 食種数）× 100。子供=児童、大人=職員。',
        ];

        foreach ($groups as $label => $level) {
            $rows = $this->roomUsageService->getRoomUsage($dateFrom, $dateTo, null, $level);
            $lines[] = sprintf('#### %s', $label);
            if (empty($rows)) {
                $lines[] = '- データなし';
                continue;
            }
            foreach ($rows as $row) {
                $lines[] = sprintf(
                    '- %s: 使用率%.1f%%（在籍%d人 / 食べた%d回 / 上限%d回）',
                    (string)$row['room_name'],
                    (float)$row['usage_rate'],
                    (int)$row['user_count'],
                    (int)$row['eat_count'],
                    (int)$row['capacity']
                );
            }
        }

        return implode("\n", $lines);
    }

    /**
     * 職員（大人）別の入力状況を構築する。未入力（0件）の職員も含める。
     *
     * 「最も入力していない職員」を判定できるよう、在籍職員（i_user_level=0）全員を
     * 分母とし、期間内の申告レコード件数を入力の少ない順に並べる。
     * 職員は [U:<ハッシュ>] トークンで仮名化し、氏名・内部IDは外部AIへ渡さない。
     */
    private function buildStaffInputSummary(string $dateFrom, string $dateTo): string
    {
        $userTable = TableRegistry::getTableLocator()->get('MUserInfo');
        $staff = $userTable->find()
            ->select(['i_id_user'])
            ->where([
                'i_user_level' => self::LEVEL_STAFF,
                'i_del_flag'   => 0,
            ])
            ->disableHydration()
            ->toArray();

        if (empty($staff)) {
            return "### 職員別の入力状況\n- データなし";
        }

        // 期間内の職員別の申告レコード件数（食べる・食べない両方）を集計する
        $counts = $this->individualTable()->find()
            ->select(['user_id' => 'i_id_user', 'total' => 'COUNT(*)'])
            ->where([
                'd_reservation_date >=' => $dateFrom,
                'd_reservation_date <=' => $dateTo,
            ])
            ->group(['i_id_user'])
            ->disableHydration()
            ->toArray();

        $countByUser = [];
        foreach ($counts as $row) {
            $countByUser[(int)$row['user_id']] = (int)$row['total'];
        }

        // 未入力（0件）の職員も含めて入力件数の少ない順に並べる
        $staffCounts = [];
        foreach ($staff as $row) {
            $userId = (int)$row['i_id_user'];
            $staffCounts[$userId] = $countByUser[$userId] ?? 0;
        }
        asort($staffCounts);

        $lines = [
            '### 職員別の入力状況（入力の少ない順・未入力を含む）',
            '入力件数は期間内の申告レコード数（食べる・食べない両方）。職員は [U:<ハッシュ>] トークンで表し、回答でもトークン表記をそのまま使う。',
        ];
        foreach ($staffCounts as $userId => $total) {
            $lines[] = sprintf(
                '- [U:%s]: 入力%d件%s',
                $this->userTokenizer->tokenize($userId),
                $total,
                $total === 0 ? '（未入力）' : ''
            );
        }

        return implode("\n", $lines);
    }

    /**
     * 利用者別の申告集計を構築する。
     *
     * 仮名化方針: 利用者は [U:<ハッシュ>] トークンでのみ表現し、氏名・内部IDは含めない。
     * トークンはSECURITY_SALTを鍵としたHMACで、外部からは元IDを逆算できない。
     * トークンから氏名への変換は画面側（ブラウザ）で行うため、外部AI APIに個人情報は渡らない。
     */
    private function buildUserSummary(string $dateFrom, string $dateTo): string
    {
        $rows = $this->individualTable()->find()
            ->select([
                'user_id'  => 'i_id_user',
                'eat'      => 'SUM(CASE WHEN eat_flag = 1 THEN 1 ELSE 0 END)',
                'no_eat'   => 'SUM(CASE WHEN eat_flag = 1 THEN 0 ELSE 1 END)',
                'changed'  => 'SUM(CASE WHEN i_change_flag = 1 THEN 1 ELSE 0 END)',
            ])
            ->where([
                'd_reservation_date >=' => $dateFrom,
                'd_reservation_date <=' => $dateTo,
            ])
            ->group(['i_id_user'])
            ->orderDesc('no_eat')
            ->disableHydration()
            ->toArray();

        $totalCount  = count($rows);
        $displayRows = array_slice($rows, 0, self::USER_SUMMARY_LIMIT);

        $lines = [
            '### 利用者別の申告集計',
            '利用者は [U:<ハッシュ>] トークンで表す。回答で利用者に言及するときは必ずこのトークン表記をそのまま使うこと（氏名は不明であり、勝手に作らない）。',
        ];
        foreach ($displayRows as $row) {
            $lines[] = sprintf(
                '- [U:%s]: 食べる%d件 / 食べない%d件 / 直前変更%d件',
                $this->userTokenizer->tokenize((int)$row['user_id']),
                (int)$row['eat'],
                (int)$row['no_eat'],
                (int)$row['changed']
            );
        }
        if ($totalCount === 0) {
            $lines[] = '- データなし';
        } elseif ($totalCount > self::USER_SUMMARY_LIMIT) {
            $lines[] = sprintf('（他 %d 名は省略）', $totalCount - self::USER_SUMMARY_LIMIT);
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
