<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\Fake;

use App\Domain\Repository\RoomMembershipRepositoryInterface;

/**
 * テスト用の部屋・所属 Repository。$members に "userId:roomId" を入れると有効な所属になる。
 */
final class FakeRoomMembershipRepository implements RoomMembershipRepositoryInterface
{
    /**
     * @param list<string>       $members "userId:roomId"
     * @param list<int>          $deletedRooms
     * @param array<int, string> $names   部屋ID => 部屋名
     */
    public function __construct(public array $members = [], public array $deletedRooms = [], public array $names = [])
    {
    }

    public function isActiveMember(int $userId, int $roomId): bool
    {
        return in_array("{$userId}:{$roomId}", $this->members, true) && !in_array($roomId, $this->deletedRooms, true);
    }

    public function isRoomAvailable(int $roomId): bool
    {
        return !in_array($roomId, $this->deletedRooms, true);
    }

    /** @var array<string, int> 部屋名 => 部屋 ID（roomIdByName 用） */
    public array $roomIdsByName = [];

    public function activeRoomIds(int $userId): array
    {
        $ids = [];
        foreach ($this->members as $key) {
            [$uid, $rid] = array_map('intval', explode(':', (string)$key));
            if ($uid === $userId) {
                $ids[] = $rid;
            }
        }

        return array_values(array_unique($ids));
    }

    public function selectableRooms(?array $onlyRoomIds = null): array
    {
        $rooms = $this->names;

        return $onlyRoomIds === null ? $rooms : array_intersect_key($rooms, array_flip($onlyRoomIds));
    }

    public function roomIdByName(string $roomName): ?int
    {
        return $this->roomIdsByName[$roomName] ?? null;
    }

    public function roomNames(array $roomIds): array
    {
        return array_intersect_key($this->names, array_flip($roomIds));
    }
}
