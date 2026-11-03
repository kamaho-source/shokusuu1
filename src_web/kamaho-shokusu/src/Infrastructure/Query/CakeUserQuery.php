<?php
declare(strict_types=1);

namespace App\Infrastructure\Query;

use App\Application\Query\UserQueryInterface;
use App\Domain\Entity\UserAccount;
use App\Domain\Repository\RoomMembershipRepositoryInterface;
use App\Domain\Repository\UserAccountRepositoryInterface;

/**
 * UserQueryInterface の実装。判定は Repository に1つだけあるので、それを呼ぶ。
 */
final class CakeUserQuery implements UserQueryInterface
{
    public function __construct(
        private readonly UserAccountRepositoryInterface $users,
        private readonly RoomMembershipRepositoryInterface $rooms,
    ) {}

    public function find(int $userId): ?UserAccount
    {
        return $this->users->find($userId);
    }

    public function exists(int $userId): bool
    {
        return $this->users->find($userId) !== null;
    }

    public function loginAccountExists(string $loginAccount): bool
    {
        return $this->users->loginAccountExists($loginAccount);
    }

    public function findByLoginAccount(string $loginAccount): ?UserAccount
    {
        return $this->users->findByLoginAccount($loginAccount);
    }

    public function isActive(int $userId): bool
    {
        $user = $this->users->find($userId);

        return $user !== null && !$user->isDeleted();
    }

    public function selectableRoomsFor(int $actorId): array
    {
        return $this->rooms->selectableRooms($this->rooms->activeRoomIds($actorId));
    }

    public function passwordResetTargets(): array
    {
        return $this->users->activeUserNames();
    }
}
