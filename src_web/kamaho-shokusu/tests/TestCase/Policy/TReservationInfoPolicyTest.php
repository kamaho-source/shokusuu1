<?php
declare(strict_types=1);

namespace App\Test\TestCase\Policy;

use App\Model\Entity\TReservationInfo;
use App\Policy\TReservationInfoPolicy;
use Cake\TestSuite\TestCase;

class TReservationInfoPolicyTest extends TestCase
{
    public function testCanAddAsStaffAllowed(): void
    {
        $policy = new TReservationInfoPolicy(new TestRoomAccessService([1 => [1]]));
        $identity = new TestIdentity([
            'i_id_user' => 1,
            'i_admin' => 0,
            'i_user_level' => 0,
        ]);

        $resource = new TReservationInfo();
        $this->assertTrue($policy->canAdd($identity, $resource));
    }

    public function testCanAddAsChildDenied(): void
    {
        $policy = new TReservationInfoPolicy(new TestRoomAccessService([1 => [1]]));
        $identity = new TestIdentity([
            'i_id_user' => 1,
            'i_admin' => 0,
            'i_user_level' => 1,
        ]);

        $resource = new TReservationInfo();
        $this->assertFalse($policy->canAdd($identity, $resource));
    }

    public function testCanGetUsersByRoomOwnRoomAllowed(): void
    {
        $policy = new TReservationInfoPolicy(new TestRoomAccessService([10 => [1, 2]]));
        $identity = new TestIdentity([
            'i_id_user' => 10,
            'i_admin' => 0,
            'i_user_level' => 0,
        ]);

        $resource = new TReservationInfo();
        $resource->set('i_id_room', 2, ['guard' => false]);

        $this->assertTrue($policy->canGetUsersByRoom($identity, $resource));
    }

    public function testCanGetUsersByRoomOtherRoomDenied(): void
    {
        $policy = new TReservationInfoPolicy(new TestRoomAccessService([10 => [1, 2]]));
        $identity = new TestIdentity([
            'i_id_user' => 10,
            'i_admin' => 0,
            'i_user_level' => 0,
        ]);

        $resource = new TReservationInfo();
        $resource->set('i_id_room', 3, ['guard' => false]);

        $this->assertFalse($policy->canGetUsersByRoom($identity, $resource));
    }

    public function testCanGetUsersByRoomOfficeUserAllowedForOfficeRoom(): void
    {
        $policy = new TReservationInfoPolicy(new TestRoomAccessService([10 => [1]], [10 => true]));
        $identity = new TestIdentity([
            'i_id_user' => 10,
            'i_admin' => 0,
            'i_user_level' => 0,
        ]);

        $resource = new TReservationInfo();
        $resource->set('i_id_room', 1, ['guard' => false]);

        $this->assertTrue($policy->canGetUsersByRoom($identity, $resource));
    }

    public function testCanGetUsersByRoomOfficeUserOtherRoomDenied(): void
    {
        $policy = new TReservationInfoPolicy(new TestRoomAccessService([10 => [1]], [10 => true]));
        $identity = new TestIdentity([
            'i_id_user' => 10,
            'i_admin' => 0,
            'i_user_level' => 0,
        ]);

        $resource = new TReservationInfo();
        $resource->set('i_id_room', 999, ['guard' => false]);

        $this->assertFalse($policy->canGetUsersByRoom($identity, $resource));
    }

    public function testCanCopyAdminAllowedNonAdminDenied(): void
    {
        $policy = new TReservationInfoPolicy(new TestRoomAccessService([]));
        $admin = new TestIdentity([
            'i_id_user' => 1,
            'i_admin' => 1,
            'i_user_level' => 0,
        ]);
        $nonAdmin = new TestIdentity([
            'i_id_user' => 2,
            'i_admin' => 0,
            'i_user_level' => 0,
        ]);

        $resource = new TReservationInfo();
        $this->assertTrue($policy->canCopy($admin, $resource));
        $this->assertFalse($policy->canCopy($nonAdmin, $resource));
    }

    public function testCanToggleBlockLeaderAllowsOtherUserInSameRoom(): void
    {
        $policy = new TReservationInfoPolicy(new TestRoomAccessService([10 => [1]]));
        $identity = new TestIdentity([
            'i_id_user' => 10,
            'i_admin' => 2,
            'i_user_level' => 1,
        ]);

        $resource = new TReservationInfo();
        $resource->set('i_id_user', 20, ['guard' => false]);
        $resource->set('i_id_room', 1, ['guard' => false]);

        $this->assertTrue($policy->canToggle($identity, $resource));
    }

    /**
     * バグ7: 職員・管理者でなくても部屋グループに所属していれば直前編集を許可する。
     * （コントローラー側の早期チェック userHasRoomAccess と同じ判定基準）
     */
    public function testCanChangeEditRoomAffiliatedUserAllowed(): void
    {
        $policy = new TReservationInfoPolicy(new TestRoomAccessService([10 => [1]]));
        $identity = new TestIdentity([
            'i_id_user' => 10,
            'i_admin' => 0,
            'i_user_level' => 1, // 職員でも管理者でもない
        ]);

        $this->assertTrue($policy->canChangeEdit($identity, new TReservationInfo()));
    }

    public function testCanChangeEditWithoutAffiliationDenied(): void
    {
        $policy = new TReservationInfoPolicy(new TestRoomAccessService([]));
        $identity = new TestIdentity([
            'i_id_user' => 10,
            'i_admin' => 0,
            'i_user_level' => 1,
        ]);

        $this->assertFalse($policy->canChangeEdit($identity, new TReservationInfo()));
    }

    /**
     * バグ3: 直前一括編集の送信は、指定部屋にアクセスできる場合のみ許可する。
     */
    public function testCanBulkChangeEditSubmitOtherRoomDenied(): void
    {
        $policy = new TReservationInfoPolicy(new TestRoomAccessService([10 => [1]]));
        $identity = new TestIdentity([
            'i_id_user' => 10,
            'i_admin' => 0,
            'i_user_level' => 0,
        ]);

        $resource = new TReservationInfo();
        $resource->set('i_id_room', 99, ['guard' => false]);

        $this->assertFalse($policy->canBulkChangeEditSubmit($identity, $resource));
    }

    public function testCanBulkChangeEditSubmitOwnRoomAllowed(): void
    {
        $policy = new TReservationInfoPolicy(new TestRoomAccessService([10 => [1]]));
        $identity = new TestIdentity([
            'i_id_user' => 10,
            'i_admin' => 0,
            'i_user_level' => 0,
        ]);

        $resource = new TReservationInfo();
        $resource->set('i_id_room', 1, ['guard' => false]);

        $this->assertTrue($policy->canBulkChangeEditSubmit($identity, $resource));
    }

    public function testCanToggleBlockLeaderDeniedForOtherRoom(): void
    {
        $policy = new TReservationInfoPolicy(new TestRoomAccessService([10 => [1]]));
        $identity = new TestIdentity([
            'i_id_user' => 10,
            'i_admin' => 2,
            'i_user_level' => 1,
        ]);

        $resource = new TReservationInfo();
        $resource->set('i_id_user', 20, ['guard' => false]);
        $resource->set('i_id_room', 3, ['guard' => false]);

        $this->assertFalse($policy->canToggle($identity, $resource));
    }
}