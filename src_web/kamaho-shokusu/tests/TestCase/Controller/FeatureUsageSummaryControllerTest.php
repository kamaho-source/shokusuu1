<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Service\FeatureUsageSummaryService;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * FeatureUsageSummaryController 統合テスト（システム管理者のみ閲覧可）
 *
 * 時間帯集計の HOUR() は MySQL 関数のため、テスト用 sqlite に同名関数を登録して実行する。
 */
class FeatureUsageSummaryControllerTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = ['app.TAuditLog', 'app.MUserInfo'];

    protected function setUp(): void
    {
        parent::setUp();
        $driver = TableRegistry::getTableLocator()->get('TAuditLog')->getConnection()->getDriver();
        $driver->connect();
        (new \ReflectionProperty($driver, 'pdo'))->getValue($driver)->sqliteCreateFunction(
            'HOUR',
            static fn(?string $dt): ?int => $dt === null ? null : (int)substr($dt, 11, 2),
            1
        );
    }

    private function login(int $userId, string $account): void
    {
        $this->session(['Auth' => [
            'i_id_user' => $userId,
            'c_login_account' => $account,
            'c_user_name' => 'テスト',
            'i_id_room' => 1,
        ]]);
    }

    public function testIndex_systemAdmin_rendersDashboard(): void
    {
        $this->login(4, 'system_admin_user');
        $this->get('/FeatureUsageSummary?month=2026-06');

        $this->assertResponseOk();
        $this->assertResponseContains('2026');
        $this->assertSame('2026-06', $this->viewVariable('yearMonth'));
        $this->assertSame(FeatureUsageSummaryService::CATEGORY_LABELS, $this->viewVariable('categories'));
        $this->assertSame(2, $this->viewVariable('summary')['total_operations']); // 失敗ログ(1件)は除外
        $this->assertCount(24, $this->viewVariable('hourlyUsage')['hours']);
        $this->assertCount(12, $this->viewVariable('monthOptions'));
    }

    public function testIndex_categoryFilterIsApplied(): void
    {
        $this->login(4, 'system_admin_user');
        $this->get('/FeatureUsageSummary?month=2026-06&category=approval');

        $this->assertResponseOk();
        $this->assertSame('approval', $this->viewVariable('category'));
        $this->assertSame(1, $this->viewVariable('summary')['total_operations']);
    }

    public function testIndex_invalidMonth_fallsBackToCurrentMonth(): void
    {
        $this->login(4, 'system_admin_user');
        $this->get('/FeatureUsageSummary?month=not-a-month');

        $this->assertResponseOk();
        $this->assertSame(date('Y-m'), $this->viewVariable('yearMonth'));
    }

    public function testIndex_defaultsToCurrentMonth(): void
    {
        $this->login(4, 'system_admin_user');
        $this->get('/FeatureUsageSummary');

        $this->assertResponseOk();
        $this->assertSame(date('Y-m'), $this->viewVariable('yearMonth'));
    }

    /**
     * @return array<string, array{int, string}>
     */
    public static function nonSystemAdminProvider(): array
    {
        return [
            '管理者' => [1, 'admin_user'],
            '一般職員' => [2, 'staff_user'],
        ];
    }

    /**
     * @dataProvider nonSystemAdminProvider
     */
    public function testIndex_nonSystemAdmin_redirectedWithFlash(int $userId, string $account): void
    {
        $this->login($userId, $account);
        $this->get('/FeatureUsageSummary');

        $this->assertRedirect(['controller' => 'Pages', 'action' => 'dashboard']);
        $this->assertSession('この機能はシステム管理者のみ利用できます。', 'Flash.flash.0.message');
    }

    public function testIndex_notLoggedIn_redirectsToLogin(): void
    {
        $this->get('/FeatureUsageSummary');

        $this->assertRedirectContains('login');
    }
}
