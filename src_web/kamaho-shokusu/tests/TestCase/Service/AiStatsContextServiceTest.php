<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Infrastructure\AI\UserTokenizer;
use App\Service\AiStatsContextService;
use App\Service\FeatureUsageSummaryService;
use App\Service\RoomUsageService;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * AiStatsContextService テスト
 *
 * 各セクションの組み立てを private メソッド単位で検証する。
 * 週別推移（buildWeeklyTrend）は MySQL 専用 SQL（DATE_SUB ... INTERVAL）を使うため
 * テスト用 sqlite では実行できず、build() 全体もここでは呼ばない。
 */
class AiStatsContextServiceTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserInfo',
        'app.MRoomInfo',
        'app.TIndividualReservationInfo',
        'app.TAuditLog',
    ];

    private UserTokenizer $tokenizer;
    private string $from;
    private string $to;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tokenizer = new UserTokenizer('test-salt');
        $this->from = date('Y-m-d', strtotime('-28 days'));
        $this->to   = date('Y-m-d', strtotime('+7 days'));
    }

    private function service(?RoomUsageService $roomUsage = null): AiStatsContextService
    {
        return new AiStatsContextService(
            new FeatureUsageSummaryService(),
            $this->tokenizer,
            $roomUsage ?? $this->createMock(RoomUsageService::class)
        );
    }

    private function call(AiStatsContextService $svc, string $method): string
    {
        $m = new \ReflectionMethod($svc, $method);

        return (string)$m->invoke($svc, ...($method === 'buildFeatureUsageSummary' ? [] : [$this->from, $this->to]));
    }

    private function seed(int $user, int $daysAgo, int $type, ?int $eat, ?int $change, int $approval, int $room = 1): void
    {
        $table = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $table->getConnection()->insert('t_individual_reservation_info', [
            'i_id_user' => $user,
            'd_reservation_date' => date('Y-m-d', strtotime("-{$daysAgo} days")),
            'i_reservation_type' => $type,
            'i_id_room' => $room,
            'eat_flag' => $eat,
            'i_change_flag' => $change,
            'i_approval_status' => $approval,
            'i_version' => 1,
        ]);
    }

    private function seedStandard(): void
    {
        $this->seed(1, 3, 1, 1, null, 0); // 朝食・食べる・未承認
        $this->seed(2, 3, 2, 0, 1, 1);    // 昼食・直前変更で食べる・ブロック長承認済
        $this->seed(2, 5, 1, 0, null, 2); // 朝食・食べない・管理者承認済
        $this->seed(4, 4, 3, 1, null, 3); // 夕食・食べる・差し戻し
    }

    public function testEmptyData_everySectionSaysNoData(): void
    {
        $svc = $this->service();

        foreach (['buildMealTypeSummary', 'buildRoomSummary', 'buildApprovalSummary', 'buildUserSummary'] as $method) {
            $this->assertStringContainsString('- データなし', $this->call($svc, $method), $method);
        }
        $eat = $this->call($svc, 'buildEatFlagSummary');
        $this->assertStringContainsString('食べる申告: 0件', $eat);
        $this->assertStringContainsString('食べる率: 0.0%', $eat);
    }

    public function testMealTypeSummary_countsOnlyEatingByType(): void
    {
        $this->seedStandard();

        $out = $this->call($this->service(), 'buildMealTypeSummary');

        $this->assertStringContainsString('- 朝食: 1食', $out);
        $this->assertStringContainsString('- 昼食: 1食', $out);
        $this->assertStringContainsString('- 夕食: 1食', $out);
        $this->assertStringNotContainsString('弁当', $out);
    }

    public function testMealTypeSummary_unknownTypeGetsGenericLabel(): void
    {
        $this->seed(1, 3, 9, 1, null, 0);

        $this->assertStringContainsString('- 食種9: 1食', $this->call($this->service(), 'buildMealTypeSummary'));
    }

    public function testRoomSummary_usesRoomTokenNotName(): void
    {
        $this->seedStandard();

        $out = $this->call($this->service(), 'buildRoomSummary');

        $this->assertStringContainsString('- [R:' . $this->tokenizer->tokenize(1) . ']: 3食', $out);
        $this->assertStringNotContainsString('Lorem', $out);
    }

    public function testEatFlagSummary_ratesAndChangedCount(): void
    {
        $this->seedStandard();

        $out = $this->call($this->service(), 'buildEatFlagSummary');

        $this->assertStringContainsString('食べる申告: 3件', $out);
        $this->assertStringContainsString('食べない申告: 1件', $out);
        $this->assertStringContainsString('食べる率: 75.0%', $out);
        $this->assertStringContainsString('直前変更（変更フラグあり）: 1件', $out);
    }

    public function testApprovalSummary_labelsEachStatus(): void
    {
        $this->seedStandard();
        $this->seed(1, 6, 1, 1, null, 7);

        $out = $this->call($this->service(), 'buildApprovalSummary');

        foreach (['未承認: 1件', 'ブロック長承認済: 1件', '管理者承認済: 1件', '差し戻し: 1件', 'ステータス7: 1件'] as $expected) {
            $this->assertStringContainsString($expected, $out);
        }
    }

    public function testStaffInputSummary_includesUnenteredStaffFirstAndExcludesDeleted(): void
    {
        $this->seedStandard();

        $out = $this->call($this->service(), 'buildStaffInputSummary');

        $lines = explode("\n", $out);
        $this->assertStringContainsString('入力の少ない順', $lines[0]);
        // 未入力の職員(5)が最初、削除済み(6)は含まれない
        $this->assertSame('- [U:' . $this->tokenizer->tokenize(5) . ']: 入力0件（未入力）', $lines[2]);
        $this->assertStringContainsString('[U:' . $this->tokenizer->tokenize(2) . ']: 入力2件', $out);
        $this->assertStringNotContainsString($this->tokenizer->tokenize(6), $out);
        // 子供(3)は職員ではない
        $this->assertStringNotContainsString($this->tokenizer->tokenize(3), $out);
    }

    public function testStaffInputSummary_noStaff_saysNoData(): void
    {
        TableRegistry::getTableLocator()->get('MUserInfo')->getConnection()
            ->execute('UPDATE m_user_info SET i_del_flag = 1');

        $this->assertStringContainsString('- データなし', $this->call($this->service(), 'buildStaffInputSummary'));
    }

    public function testUserSummary_usesTokensOnlyAndNeverNames(): void
    {
        $this->seedStandard();

        $out = $this->call($this->service(), 'buildUserSummary');

        $this->assertStringContainsString(
            '- [U:' . $this->tokenizer->tokenize(2) . ']: 食べる1件 / 食べない1件 / 直前変更1件',
            $out
        );
        $this->assertStringNotContainsString('管理者ユーザー', $out);
        $this->assertStringNotContainsString('職員ユーザー', $out);
    }

    public function testUserSummary_truncatesAfterFiftyUsers(): void
    {
        for ($u = 100; $u < 152; $u++) {
            $this->seed($u, 3, 1, 1, null, 0);
        }

        $out = $this->call($this->service(), 'buildUserSummary');

        $this->assertSame(50, substr_count($out, '- [U:'));
        $this->assertStringContainsString('（他 2 名は省略）', $out);
    }

    public function testRoomUsageRateSummary_rendersChildAndAdultGroups(): void
    {
        $roomUsage = $this->createMock(RoomUsageService::class);
        $roomUsage->method('getRoomUsage')->willReturnCallback(
            static fn(?string $f, ?string $t, ?int $meal, ?int $level): array => $level === 1
                ? [['room_id' => 1, 'usage_rate' => 62.5, 'user_count' => 4, 'eat_count' => 10, 'capacity' => 16]]
                : []
        );

        $out = $this->call($this->service($roomUsage), 'buildRoomUsageRateSummary');

        $this->assertStringContainsString('#### 子供（児童）', $out);
        $this->assertStringContainsString(
            '- [R:' . $this->tokenizer->tokenize(1) . ']: 使用率62.5%（在籍4人 / 食べた10回 / 上限16回）',
            $out
        );
        $this->assertMatchesRegularExpression('/#### 大人（職員）\n- データなし/', $out);
    }

    public function testFeatureUsageSummary_listsThisMonthsTopFeatures(): void
    {
        $now = date('Y-m-d H:i:s');
        $conn = TableRegistry::getTableLocator()->get('TAuditLog')->getConnection();
        foreach ([1, 2, 1] as $actor) {
            $conn->insert('t_audit_log', [
                'c_category' => 'user', 'c_action' => 'user_login', 'i_actor_user_id' => $actor,
                'c_actor_user_name' => 'x', 'i_result' => 1, 'dt_create' => $now,
            ]);
        }

        $out = $this->call($this->service(), 'buildFeatureUsageSummary');

        $this->assertStringContainsString('操作総数: 3件', $out);
        $this->assertStringContainsString('3回 / 利用者2人', $out);
    }

    public function testFeatureUsageSummary_noLogs_saysNoData(): void
    {
        TableRegistry::getTableLocator()->get('TAuditLog')->getConnection()->execute('DELETE FROM t_audit_log');

        $this->assertStringContainsString('- データなし', $this->call($this->service(), 'buildFeatureUsageSummary'));
    }
}
