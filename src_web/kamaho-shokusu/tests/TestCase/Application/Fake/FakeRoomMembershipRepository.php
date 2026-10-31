<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\Fake;

use App\Domain\Repository\RoomMembershipRepositoryInterface;

/**
 * テスト用の部屋・所属 Repository。$members に "userId:roomId" を入れると有効な所属になる。
 */
final class FakeRoomMembershipRepository implements RoomMembershipRepositoryInterface
{
    /** @param list<string> $members "userId:roomId" */
    public function __construct(public array $members = [], public array $deletedRooms = [])
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
}
