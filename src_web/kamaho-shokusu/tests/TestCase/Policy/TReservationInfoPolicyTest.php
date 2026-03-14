<?php
declare(strict_types=1);

namespace App\Test\TestCase\Policy;

use App\Model\Entity\TReservationInfo;
use App\Policy\TReservationInfoPolicy;
use App\Service\RoomAccessService;
use Authorization\IdentityInterface;
use Authorization\Policy\ResultInterface;
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

class TestRoomAccessService extends RoomAccessService
{
    /**
     * @param array<int, array<int>> $map
     * @param array<int, bool> $officeUsers
     */
    public function __construct(private array $map, private array $officeUsers = [])
    {
    }

    public function getUserRoomIds(int $userId): array
    {
        return $this->map[$userId] ?? [];
    }

    public function isOfficeUser(int $userId): bool
    {
        return $this->officeUsers[$userId] ?? false;
    }

    public function userCanAccessRoom(int $userId, int $roomId): bool
    {

        return in_array($roomId, $this->getUserRoomIds($userId), true);
    }
}

class TestIdentity implements IdentityInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private array $data)
    {
    }

    public function can(string $action, mixed $resource): bool
    {
        return false;
    }

    public function canResult(string $action, mixed $resource): ResultInterface
    {
        throw new \BadMethodCallException('Not used in policy tests.');
    }

    public function applyScope(string $action, mixed $resource, mixed ...$optionalArgs): mixed
    {
        return $resource;
    }

    public function getOriginalData(): \ArrayAccess|array
    {
        return $this->data;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->data[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->data[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->data[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->data[$offset]);
    }
}