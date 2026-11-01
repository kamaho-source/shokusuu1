<?php
declare(strict_types=1);

namespace App\Policy;

use App\Domain\ValueObject\UserRole;

use App\Infrastructure\Persistence\Entity\MRoomInfo;
use App\Service\RoomAccessService;
use Authorization\IdentityInterface;

class MRoomInfoPolicy
{
    private RoomAccessService $roomAccessService;

    public function __construct(?RoomAccessService $roomAccessService = null)
    {
        $this->roomAccessService = $roomAccessService ?? new RoomAccessService();
    }

    public function canIndex(?IdentityInterface $user, MRoomInfo $resource): bool
    {
        return $this->isAuthenticated($user);
    }

    public function canView(?IdentityInterface $user, MRoomInfo $resource): bool
    {
        return $this->isAuthenticated($user);
    }

    public function canAdd(?IdentityInterface $user, MRoomInfo $resource): bool
    {
        return $this->isAdmin($user);
    }

    public function canEdit(?IdentityInterface $user, MRoomInfo $resource): bool
    {
        return $this->isAdmin($user);
    }

    public function canDelete(?IdentityInterface $user, MRoomInfo $resource): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * 所属メンバーを並び替えられるか（#871）。
     * 管理者・システム管理者はすべての部屋。ブロック長は自分が今所属している部屋だけ。一般の職員・児童・その他は不可。
     */
    public function canReorderMembers(?IdentityInterface $user, MRoomInfo $resource): bool
    {
        if ($this->isAdmin($user)) {
            return true;
        }
        $identity = $this->getOriginalIdentity($user);
        if ($identity === null) {
            return false;
        }
        $get = static function (string $key) use ($identity): ?int {
            $v = is_object($identity) && method_exists($identity, 'get') ? $identity->get($key) : ($identity[$key] ?? null);
            return $v === null ? null : (int)$v;
        };
        if (!UserRole::isBlockLeader((int)$get('i_admin'))) {
            return false;
        }

        return $this->roomAccessService->isActiveMember((int)$get('i_id_user'), (int)$resource->i_id_room);
    }

    private function isAdmin(?IdentityInterface $user): bool
    {
        $identity = $this->getOriginalIdentity($user);
        if ($identity === null) {
            return false;
        }

        if (is_object($identity) && method_exists($identity, 'get')) {
            return UserRole::isAdmin((int)$identity->get('i_admin'));
        }

        if (is_array($identity)) {
            return UserRole::isAdmin((int)($identity['i_admin'] ?? 0));
        }

        if ($identity instanceof \ArrayAccess) {
            return UserRole::isAdmin((int)($identity['i_admin'] ?? 0));
        }

        return false;
    }

    private function isAuthenticated(?IdentityInterface $user): bool
    {
        return $this->getOriginalIdentity($user) !== null;
    }

    private function getOriginalIdentity(?IdentityInterface $user): object|array|null
    {
        if ($user === null) {
            return null;
        }

        return $user->getOriginalData();
    }
}
