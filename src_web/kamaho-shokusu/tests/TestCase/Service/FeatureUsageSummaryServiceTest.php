<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\FeatureUsageSummaryService;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * FeatureUsageSummaryService テスト
 *
 * getHourlyDistribution は MySQL の HOUR() を使うため、テスト用 sqlite には同名関数を登録して検証する。
 */
class FeatureUsageSummaryServiceTest extends TestCase
{
    protected array $fixtures = ['app.TAuditLog'];

    protected function setUp(): void
    {
        parent::setUp();
        $conn = TableRegistry::getTableLocator()->get('TAuditLog')->getConnection();
        $conn->execute('DELETE FROM t_audit_log');
        $driver = $conn->getDriver();
        $driver->connect();
        $pdo = (new \ReflectionProperty($driver, 'pdo'))->getValue($driver);
        $pdo->sqliteCreateFunction(
            'HOUR',
            static fn(?string $dt): ?int => $dt === null ? null : (int)substr($dt, 11, 2),
            1
        );
    }

    private function log(string $category, string $action, ?int $actor, string $at, bool $ok = true): void
    {
        TableRegistry::getTableLocator()->get('TAuditLog')->getConnection()->insert('t_audit_log', [
            'c_category' => $category,
            'c_action' => $action,
            'i_actor_user_id' => $actor,
            'c_actor_user_name' => 'x',
            'i_result' => $ok ? 1 : 0,
            'dt_create' => $at,
        ]);
    }

    private function seed(): void
    {
        $this->log('user', 'user_login', 1, '2026-03-05 09:10:00');
        $this->log('user', 'user_login', 2, '2026-03-20 09:50:00');
        $this->log('user', 'user_login', 1, '2026-03-31 23:59:59');
        $this->log('approval', 'approval_block_leader', 2, '2026-03-10 14:00:00');
        $this->log('user', 'user_login', 3, '2026-03-11 10:00:00', false); // 失敗は集計しない
        $this->log('user', 'user_login', 3, '2026-04-01 00:00:00');         // 別の月
    }

    public function testGetSummary_aggregatesSuccessfulOpsInMonthOrderedByTotal(): void
    {
        $this->seed();

        $s = (new FeatureUsageSummaryService())->getSummary('2026-03');

        $this->assertSame(4, $s['total_operations']);
        $this->assertCount(2, $s['rows']);
        $top = $s['rows'][0];
        $this->assertSame('user_login', $top['action']);
        $this->assertSame(3, $top['total']);
        $this->assertSame(2, $top['unique_users']);
        $this->assertSame('2026-03-31', $top['last_used']);
        $this->assertSame('user', $top['category']);
        $this->assertSame(FeatureUsageSummaryService::CATEGORY_LABELS['user'] ?? 'user', $top['category_label']);
        $this->assertSame($top['label'], $s['top_feature']);
        $this->assertSame('approval_block_leader', $s['rows'][1]['action']);
    }

    public function testGetSummary_categoryFilter(): void
    {
        $this->seed();

        $s = (new FeatureUsageSummaryService())->getSummary('2026-03', 'approval');

        $this->assertSame(1, $s['total_operations']);
        $this->assertSame('approval_block_leader', $s['rows'][0]['action']);
    }

    public function testGetSummary_emptyCategoryStringMeansNoFilter(): void
    {
        $this->seed();

        $this->assertSame(4, (new FeatureUsageSummaryService())->getSummary('2026-03', '')['total_operations']);
    }

    public function testGetSummary_noData_returnsEmptyResult(): void
    {
        $s = (new FeatureUsageSummaryService())->getSummary('2020-01');

        $this->assertSame(['rows' => [], 'total_operations' => 0, 'top_feature' => ''], $s);
    }

    public function testGetHourlyDistribution_has24BucketsAndPeak(): void
    {
        $this->seed();

        $h = (new FeatureUsageSummaryService())->getHourlyDistribution('2026-03');

        $this->assertCount(24, $h['hours']);
        $this->assertSame('09:00', $h['hours'][9]['label']);
        $this->assertSame(2, $h['hours'][9]['total']);
        $this->assertSame(1, $h['hours'][14]['total']);
        $this->assertSame(1, $h['hours'][23]['total']);
        $this->assertSame(0, $h['hours'][0]['total']);
        $this->assertSame(9, $h['peak_hour']);
        $this->assertSame(2, $h['peak_total']);
    }

    public function testGetHourlyDistribution_categoryFilterAndEmpty(): void
    {
        $this->seed();
        $svc = new FeatureUsageSummaryService();

        $this->assertSame(14, $svc->getHourlyDistribution('2026-03', 'approval')['peak_hour']);

        $empty = $svc->getHourlyDistribution('2020-01');
        $this->assertNull($empty['peak_hour']);
        $this->assertSame(0, $empty['peak_total']);
    }

    public function testGetMonthOptions_returnsLast12MonthsNewestFirst(): void
    {
        $opts = (new FeatureUsageSummaryService())->getMonthOptions();

        $this->assertCount(12, $opts);
        $this->assertSame(date('Y-m'), array_key_first($opts));
        $this->assertSame(date('Y年n月'), $opts[date('Y-m')]);
    }

    /**
     * 月末・うるう日・年始でも、月が重複せず12か月が連続して並ぶ（#919）
     */
    public function testGetMonthOptions_isContinuousOnMonthEnd(): void
    {
        $cases = [
            '2026-10-31' => ['2026-10', '2026-09', '2025-11'],
            '2026-03-31' => ['2026-03', '2026-02', '2025-04'],
            '2028-02-29' => ['2028-02', '2028-01', '2027-03'],
            '2027-01-01' => ['2027-01', '2026-12', '2026-02'],
        ];
        try {
            foreach ($cases as $today => [$first, $second, $last]) {
                DateTime::setTestNow(new DateTime($today . ' 23:30:00', 'Asia/Tokyo'));
                $keys = array_keys((new FeatureUsageSummaryService())->getMonthOptions());

                $this->assertCount(12, $keys, $today);
                $this->assertSame([$first, $second], array_slice($keys, 0, 2), $today);
                $this->assertSame($last, $keys[11], $today);
            }
        } finally {
            DateTime::setTestNow(null);
        }
    }
}
