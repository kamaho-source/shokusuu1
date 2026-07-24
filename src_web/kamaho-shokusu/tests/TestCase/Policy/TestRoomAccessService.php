<?php
declare(strict_types=1);

namespace App\Test\TestCase\Policy;

use App\Service\RoomAccessService;

/**
 * ポリシーテスト用 RoomAccessService ダブル。
 */
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
