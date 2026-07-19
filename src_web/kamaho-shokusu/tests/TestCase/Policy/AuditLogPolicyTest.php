<?php
declare(strict_types=1);

namespace App\Test\TestCase\Policy;

use App\Controller\AuditLogController;
use App\Policy\AuditLogPolicy;
use Cake\TestSuite\TestCase;

/**
 * AuditLogPolicy テスト
 *
 * - システム管理者（i_admin === 3）: 全テナントのログを閲覧可能
 * - テナント管理者（i_admin === 4）: 自テナントのログを閲覧可能（クエリ層でスコープ強制）
 * - それ以外: アクセス拒否
 */
class AuditLogPolicyTest extends TestCase
{
    private AuditLogPolicy $policy;
    private AuditLogController $resource;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy   = new AuditLogPolicy();
        $this->resource = $this->createMock(AuditLogController::class);
    }

    // ----------------------------------------------------------------
    // canIndex
    // ----------------------------------------------------------------

    public function testCanIndex_systemAdmin_allowed(): void
    {
        $user = new PolicyTestIdentity(['i_admin' => 3]);
        $this->assertTrue($this->policy->canIndex($user, $this->resource));
    }

    public function testCanIndex_tenantAdmin_allowed(): void
    {
        $user = new PolicyTestIdentity(['i_admin' => 4]);
        $this->assertTrue($this->policy->canIndex($user, $this->resource));
    }

    /**
     * @dataProvider deniedRolesProvider
     */
    public function testCanIndex_otherRoles_denied(int $adminLevel): void
    {
        $user = new PolicyTestIdentity(['i_admin' => $adminLevel]);
        $this->assertFalse($this->policy->canIndex($user, $this->resource));
    }

    public function testCanIndex_nullUser_denied(): void
    {
        $this->assertFalse($this->policy->canIndex(null, $this->resource));
    }

    // ----------------------------------------------------------------
    // canExport
    // ----------------------------------------------------------------

    public function testCanExport_systemAdmin_allowed(): void
    {
        $user = new PolicyTestIdentity(['i_admin' => 3]);
        $this->assertTrue($this->policy->canExport($user, $this->resource));
    }

    public function testCanExport_tenantAdmin_allowed(): void
    {
        $user = new PolicyTestIdentity(['i_admin' => 4]);
        $this->assertTrue($this->policy->canExport($user, $this->resource));
    }

    /**
     * @dataProvider deniedRolesProvider
     */
    public function testCanExport_otherRoles_denied(int $adminLevel): void
    {
        $user = new PolicyTestIdentity(['i_admin' => $adminLevel]);
        $this->assertFalse($this->policy->canExport($user, $this->resource));
    }

    public function testCanExport_nullUser_denied(): void
    {
        $this->assertFalse($this->policy->canExport(null, $this->resource));
    }

    // ----------------------------------------------------------------
    // DataProviders
    // ----------------------------------------------------------------

    public static function deniedRolesProvider(): array
    {
        return [
            'general (0)'      => [0],
            'admin (1)'        => [1],
            'block_leader (2)' => [2],
        ];
    }
}
