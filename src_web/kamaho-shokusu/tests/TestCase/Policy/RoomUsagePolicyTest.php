<?php
declare(strict_types=1);

namespace App\Test\TestCase\Policy;

use App\Controller\RoomUsageController;
use App\Policy\RoomUsagePolicy;
use Cake\TestSuite\TestCase;

/**
 * RoomUsagePolicy テスト
 *
 * システム管理者（i_admin === 3）のみが canIndex / canRoomUsage / canLowUsageRooms を許可されることを検証する。
 */
class RoomUsagePolicyTest extends TestCase
{
    private RoomUsagePolicy $policy;
    private RoomUsageController $resource;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy   = new RoomUsagePolicy();
        $this->resource = $this->createMock(RoomUsageController::class);
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
    // canRoomUsage
    // ----------------------------------------------------------------

    public function testCanRoomUsage_systemAdmin_allowed(): void
    {
        $user = new PolicyTestIdentity(['i_admin' => 3]);
        $this->assertTrue($this->policy->canRoomUsage($user, $this->resource));
    }

    /**
     * @dataProvider nonSystemAdminProvider
     */
    public function testCanRoomUsage_nonSystemAdmin_denied(int $adminLevel): void
    {
        $user = new PolicyTestIdentity(['i_admin' => $adminLevel]);
        $this->assertFalse($this->policy->canRoomUsage($user, $this->resource));
    }

    public function testCanRoomUsage_nullUser_denied(): void
    {
        $this->assertFalse($this->policy->canRoomUsage(null, $this->resource));
    }

    // ----------------------------------------------------------------
    // canLowUsageRooms
    // ----------------------------------------------------------------

    public function testCanLowUsageRooms_systemAdmin_allowed(): void
    {
        $user = new PolicyTestIdentity(['i_admin' => 3]);
        $this->assertTrue($this->policy->canLowUsageRooms($user, $this->resource));
    }

    /**
     * @dataProvider nonSystemAdminProvider
     */
    public function testCanLowUsageRooms_nonSystemAdmin_denied(int $adminLevel): void
    {
        $user = new PolicyTestIdentity(['i_admin' => $adminLevel]);
        $this->assertFalse($this->policy->canLowUsageRooms($user, $this->resource));
    }

    public function testCanLowUsageRooms_nullUser_denied(): void
    {
        $this->assertFalse($this->policy->canLowUsageRooms(null, $this->resource));
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
