<?php
declare(strict_types=1);

namespace App\Test\TestCase\Policy;

use App\Policy\AuditLogPolicy;
use Cake\TestSuite\TestCase;

/**
 * AuditLogPolicy テスト
 *
 * システム管理者（i_admin === 3）のみが canIndex / canExport を許可されることを検証する。
 */
class AuditLogPolicyTest extends TestCase
{
    private AuditLogPolicy $policy;
    private object $resource;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy   = new AuditLogPolicy();
        $this->resource = new \stdClass();
    }

    // ----------------------------------------------------------------
    // canIndex
    // ----------------------------------------------------------------

    public function testCanIndex_systemAdmin_allowed(): void
    {
        $user = new PolicyTestIdentity(['i_admin' => 3]);
        $this->assertTrue($this->policy->canIndex($user, $this->resource));
    }

    /**
     * @dataProvider nonSystemAdminProvider
     */
    public function testCanIndex_nonSystemAdmin_denied(int $adminLevel): void
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

    /**
     * @dataProvider nonSystemAdminProvider
     */
    public function testCanExport_nonSystemAdmin_denied(int $adminLevel): void
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

    public static function nonSystemAdminProvider(): array
    {
        return [
            'general (0)'      => [0],
            'admin (1)'        => [1],
            'block_leader (2)' => [2],
        ];
    }
}
