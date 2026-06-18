<?php
declare(strict_types=1);

namespace App\Test\TestCase\Policy;

use App\Model\Entity\MNotice;
use App\Policy\MNoticePolicy;
use Cake\TestSuite\TestCase;

/**
 * MNoticePolicy テスト
 *
 * 管理者（i_admin = 1 or 3）のみが canIndex / canAdd / canEdit / canDelete を許可されることを検証する。
 */
class MNoticePolicyTest extends TestCase
{
    private MNoticePolicy $policy;
    private MNotice $resource;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy   = new MNoticePolicy();
        $this->resource = $this->createMock(MNotice::class);
    }

    // ----------------------------------------------------------------
    // canIndex
    // ----------------------------------------------------------------

    /**
     * @dataProvider adminUserProvider
     */
    public function testCanIndex_admin_allowed(int $adminLevel): void
    {
        $user = new PolicyTestIdentity(['i_admin' => $adminLevel]);
        $this->assertTrue($this->policy->canIndex($user, $this->resource));
    }

    /**
     * @dataProvider nonAdminUserProvider
     */
    public function testCanIndex_nonAdmin_denied(int $adminLevel): void
    {
        $user = new PolicyTestIdentity(['i_admin' => $adminLevel]);
        $this->assertFalse($this->policy->canIndex($user, $this->resource));
    }

    public function testCanIndex_nullUser_denied(): void
    {
        $this->assertFalse($this->policy->canIndex(null, $this->resource));
    }

    // ----------------------------------------------------------------
    // canAdd
    // ----------------------------------------------------------------

    /**
     * @dataProvider adminUserProvider
     */
    public function testCanAdd_admin_allowed(int $adminLevel): void
    {
        $user = new PolicyTestIdentity(['i_admin' => $adminLevel]);
        $this->assertTrue($this->policy->canAdd($user, $this->resource));
    }

    /**
     * @dataProvider nonAdminUserProvider
     */
    public function testCanAdd_nonAdmin_denied(int $adminLevel): void
    {
        $user = new PolicyTestIdentity(['i_admin' => $adminLevel]);
        $this->assertFalse($this->policy->canAdd($user, $this->resource));
    }

    public function testCanAdd_nullUser_denied(): void
    {
        $this->assertFalse($this->policy->canAdd(null, $this->resource));
    }

    // ----------------------------------------------------------------
    // canEdit
    // ----------------------------------------------------------------

    /**
     * @dataProvider adminUserProvider
     */
    public function testCanEdit_admin_allowed(int $adminLevel): void
    {
        $user = new PolicyTestIdentity(['i_admin' => $adminLevel]);
        $this->assertTrue($this->policy->canEdit($user, $this->resource));
    }

    /**
     * @dataProvider nonAdminUserProvider
     */
    public function testCanEdit_nonAdmin_denied(int $adminLevel): void
    {
        $user = new PolicyTestIdentity(['i_admin' => $adminLevel]);
        $this->assertFalse($this->policy->canEdit($user, $this->resource));
    }

    public function testCanEdit_nullUser_denied(): void
    {
        $this->assertFalse($this->policy->canEdit(null, $this->resource));
    }

    // ----------------------------------------------------------------
    // canDelete
    // ----------------------------------------------------------------

    /**
     * @dataProvider adminUserProvider
     */
    public function testCanDelete_admin_allowed(int $adminLevel): void
    {
        $user = new PolicyTestIdentity(['i_admin' => $adminLevel]);
        $this->assertTrue($this->policy->canDelete($user, $this->resource));
    }

    /**
     * @dataProvider nonAdminUserProvider
     */
    public function testCanDelete_nonAdmin_denied(int $adminLevel): void
    {
        $user = new PolicyTestIdentity(['i_admin' => $adminLevel]);
        $this->assertFalse($this->policy->canDelete($user, $this->resource));
    }

    public function testCanDelete_nullUser_denied(): void
    {
        $this->assertFalse($this->policy->canDelete(null, $this->resource));
    }

    // ----------------------------------------------------------------
    // DataProviders
    // ----------------------------------------------------------------

    public static function adminUserProvider(): array
    {
        return [
            'admin (1)'        => [1],
            'system_admin (3)' => [3],
        ];
    }

    public static function nonAdminUserProvider(): array
    {
        return [
            'general (0)'      => [0],
            'block_leader (2)' => [2],
        ];
    }
}
