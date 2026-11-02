<?php
declare(strict_types=1);

namespace App\Test\TestCase\Policy;

use App\Application\Query\RoomQueryInterface;

/**
 * ポリシーテスト用 RoomQueryInterface ダブル（利用者ID => 所属部屋の対応表で答える）。
 */
class TestRoomAccessService implements RoomQueryInterface
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

    public function hasAnyAffiliation(int $userId): bool
    {
        return $this->getUserRoomIds($userId) !== [];
    }

    public function isActiveMember(int $userId, int $roomId): bool
    {
        return in_array($roomId, $this->getUserRoomIds($userId), true);
    }

    public function isRoomAvailable(int $roomId): bool
    {
        return true;
    }

    public function getAccessibleRooms(int $userId): array
    {
        return array_fill_keys($this->getUserRoomIds($userId), '');
    }

    public function getRoomsByIds(array $roomIds): array
    {
        return array_fill_keys($roomIds, '');
    }

    public function getAllActiveRooms(): array
    {
        return [];
    }

    public function countActiveRooms(): int
    {
        return 0;
    }

    public function getRoomMembers(int $roomId): array
    {
        return [];
    }
}
