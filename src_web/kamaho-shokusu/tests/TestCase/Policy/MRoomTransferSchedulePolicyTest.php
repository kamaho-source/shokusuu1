<?php
declare(strict_types=1);

namespace App\Test\TestCase\Policy;

use App\Model\Entity\MRoomTransferSchedule;
use App\Policy\MRoomTransferSchedulePolicy;
use Cake\TestSuite\TestCase;

/**
 * MRoomTransferSchedulePolicy テスト
 *
 * 管理者（i_admin = 1 or 3）のみが canIndex / canAdd / canCancel を許可されることを検証する。
 */
class MRoomTransferSchedulePolicyTest extends TestCase
{
    private MRoomTransferSchedulePolicy $policy;
    private MRoomTransferSchedule $resource;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy   = new MRoomTransferSchedulePolicy();
        $this->resource = $this->createMock(MRoomTransferSchedule::class);
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
    // canCancel
    // ----------------------------------------------------------------

    /**
     * @dataProvider adminUserProvider
     */
    public function testCanCancel_admin_allowed(int $adminLevel): void
    {
        $user = new PolicyTestIdentity(['i_admin' => $adminLevel]);
        $this->assertTrue($this->policy->canCancel($user, $this->resource));
    }

    /**
     * @dataProvider nonAdminUserProvider
     */
    public function testCanCancel_nonAdmin_denied(int $adminLevel): void
    {
        $user = new PolicyTestIdentity(['i_admin' => $adminLevel]);
        $this->assertFalse($this->policy->canCancel($user, $this->resource));
    }

    public function testCanCancel_nullUser_denied(): void
    {
        $this->assertFalse($this->policy->canCancel(null, $this->resource));
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
