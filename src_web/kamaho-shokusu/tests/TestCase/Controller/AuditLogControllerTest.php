<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * AuditLogController 統合テスト
 *
 * - システム管理者（i_admin=3）のみ index / export にアクセスできる
 * - 一般管理者（i_admin=1）はリダイレクトされる
 * - export はCSVレスポンスを返す
 */
class AuditLogControllerTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.TAuditLog',
        'app.MUserInfo',
    ];

    // ----------------------------------------------------------------
    // index
    // ----------------------------------------------------------------

    public function testIndex_systemAdmin_returns200(): void
    {
        $this->setSession(3);
        $this->get('/AuditLog');
        $this->assertResponseOk();
    }

    public function testIndex_admin_redirected(): void
    {
        $this->setSession(1);
        $this->get('/AuditLog');
        $this->assertResponseSuccess();
        $this->assertRedirect();
    }

    public function testIndex_general_redirected(): void
    {
        $this->setSession(0);
        $this->get('/AuditLog');
        $this->assertResponseSuccess();
        $this->assertRedirect();
    }

    public function testIndex_blockLeader_redirected(): void
    {
        $this->setSession(2);
        $this->get('/AuditLog');
        $this->assertResponseSuccess();
        $this->assertRedirect();
    }

    // ----------------------------------------------------------------
    // index — フィルター
    // ----------------------------------------------------------------

    public function testIndex_categoryFilter_returns200(): void
    {
        $this->setSession(3);
        $this->get('/AuditLog?category=user');
        $this->assertResponseOk();
    }

    public function testIndex_actionFilter_returns200(): void
    {
        $this->setSession(3);
        $this->get('/AuditLog?action=login');
        $this->assertResponseOk();
    }

    public function testIndex_dateRangeFilter_returns200(): void
    {
        $this->setSession(3);
        $this->get('/AuditLog?date_from=2026-01-01&date_to=2026-12-31');
        $this->assertResponseOk();
    }

    public function testIndex_resultFilter_returns200(): void
    {
        $this->setSession(3);
        $this->get('/AuditLog?result=1');
        $this->assertResponseOk();
    }

    public function testIndex_actorFilter_returns200(): void
    {
        $this->setSession(3);
        $this->get('/AuditLog?actor=admin');
        $this->assertResponseOk();
    }

    public function testIndex_targetIdFilter_returns200(): void
    {
        $this->setSession(3);
        $this->get('/AuditLog?target_id=1');
        $this->assertResponseOk();
    }

    // ----------------------------------------------------------------
    // export
    // ----------------------------------------------------------------

    public function testExport_systemAdmin_returnsCsv(): void
    {
        $this->setSession(3);
        $this->get('/AuditLog/export');
        $this->assertResponseOk();
        $this->assertContentType('text/csv');
    }

    public function testExport_systemAdmin_csvHasBom(): void
    {
        $this->setSession(3);
        $this->get('/AuditLog/export');
        $body = (string)$this->_response->getBody();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body, 'CSV should start with UTF-8 BOM');
    }

    public function testExport_systemAdmin_csvHasHeader(): void
    {
        $this->setSession(3);
        $this->get('/AuditLog/export');
        $body = (string)$this->_response->getBody();
        $this->assertStringContainsString('カテゴリ', $body);
        $this->assertStringContainsString('操作種別', $body);
        $this->assertStringContainsString('操作日時', $body);
    }

    public function testExport_admin_redirected(): void
    {
        $this->setSession(1);
        $this->get('/AuditLog/export');
        $this->assertResponseSuccess();
        $this->assertRedirect();
    }

    // ----------------------------------------------------------------
    // export が実行されると audit_export ログが記録されること
    // ----------------------------------------------------------------

    public function testExport_recordsAuditLog(): void
    {
        $before = TableRegistry::getTableLocator()->get('TAuditLog')->find()->count();

        $this->setSession(3);
        $this->get('/AuditLog/export');

        $after = TableRegistry::getTableLocator()->get('TAuditLog')->find()->count();
        $this->assertSame($before + 1, $after);

        $log = TableRegistry::getTableLocator()->get('TAuditLog')
            ->find()
            ->order(['i_id_audit' => 'DESC'])
            ->first();

        $this->assertSame('system',       $log->c_category);
        $this->assertSame('audit_export', $log->c_action);
    }

    // ----------------------------------------------------------------
    // ヘルパー
    // ----------------------------------------------------------------

    private function setSession(int $adminLevel): void
    {
        $this->session([
            'Auth' => [
                'i_id_user'    => 1,
                'c_user_name'  => 'システム管理者',
                'i_admin'      => $adminLevel,
                'i_user_level' => 0,
                'i_id_room'    => 1,
            ],
        ]);
    }
}
