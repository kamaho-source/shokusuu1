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
}