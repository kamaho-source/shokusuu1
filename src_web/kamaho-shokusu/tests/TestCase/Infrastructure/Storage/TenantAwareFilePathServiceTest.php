<?php
declare(strict_types=1);

namespace App\Test\TestCase\Infrastructure\Storage;

use App\Application\Tenant\TenantContext;
use App\Infrastructure\Storage\TenantAwareFilePathService;
use Cake\TestSuite\TestCase;

/**
 * TenantAwareFilePathService テスト
 *
 * テナント・施設単位のファイルパス生成と
 * ダウンロード時のテナント帰属検証ロジックを検証する。
 */
class TenantAwareFilePathServiceTest extends TestCase
{
    private TenantAwareFilePathService $service;
    private TenantContext $ctx1;
    private TenantContext $ctx2;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new TenantAwareFilePathService();
        $this->ctx1    = new TenantContext(tenantId: 1, tenantCode: 'tenant1', tenantStatus: 'active', facilityId: 10);
        $this->ctx2    = new TenantContext(tenantId: 2, tenantCode: 'tenant2', tenantStatus: 'active', facilityId: 20);
    }

    // ----------------------------------------------------------------
    // exportsDir
    // ----------------------------------------------------------------

    public function testExportsDir_containsTenantAndFacilityId(): void
    {
        $path = $this->service->exportsDir($this->ctx1);

        $this->assertStringContainsString('tenants/1', $path);
        $this->assertStringContainsString('facilities/10', $path);
        $this->assertStringEndsWith('/exports', $path);
    }

    public function testExportsDir_differentTenants_differentPaths(): void
    {
        $path1 = $this->service->exportsDir($this->ctx1);
        $path2 = $this->service->exportsDir($this->ctx2);

        $this->assertNotSame($path1, $path2);
        $this->assertStringContainsString('tenants/1', $path1);
        $this->assertStringContainsString('tenants/2', $path2);
    }

    // ----------------------------------------------------------------
    // importsDir
    // ----------------------------------------------------------------

    public function testImportsDir_containsTenantAndFacilityId(): void
    {
        $path = $this->service->importsDir($this->ctx1);

        $this->assertStringContainsString('tenants/1', $path);
        $this->assertStringContainsString('facilities/10', $path);
        $this->assertStringEndsWith('/imports', $path);
    }

    // ----------------------------------------------------------------
    // validateFileAccess
    // ----------------------------------------------------------------

    public function testValidateFileAccess_sameTenant_returnsTrue(): void
    {
        $path = $this->service->exportsDir($this->ctx1) . '/report.csv';

        $this->assertTrue($this->service->validateFileAccess($path, $this->ctx1));
    }

    public function testValidateFileAccess_differentTenant_returnsFalse(): void
    {
        $pathForTenant1 = $this->service->exportsDir($this->ctx1) . '/report.csv';

        // テナント2のコンテキストでテナント1のパスを検証 → 拒否
        $this->assertFalse($this->service->validateFileAccess($pathForTenant1, $this->ctx2));
    }

    public function testValidateFileAccess_pathTraversal_returnsFalse(): void
    {
        $malicious = 'files/tenants/1/../../../etc/passwd';

        $this->assertFalse($this->service->validateFileAccess($malicious, $this->ctx2));
    }

    public function testValidateFileAccess_unrelatedPath_returnsFalse(): void
    {
        $this->assertFalse($this->service->validateFileAccess('/var/www/secret.txt', $this->ctx1));
    }
}
