<?php
declare(strict_types=1);

namespace App\Policy;

use App\Model\Entity\MRoomInfo;
use Authorization\IdentityInterface;

class MRoomInfoPolicy
{
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

    private function isAdmin(?IdentityInterface $user): bool
    {
        $identity = $this->getOriginalIdentity($user);
        if ($identity === null) {
            return false;
        }

        if (is_object($identity) && method_exists($identity, 'get')) {
            return (int)$identity->get('i_admin') === 1;
        }

        if (is_array($identity)) {
            return (int)($identity['i_admin'] ?? 0) === 1;
        }

        if ($identity instanceof \ArrayAccess) {
            return (int)($identity['i_admin'] ?? 0) === 1;
        }

        return false;
    }

    private function isAuthenticated(?IdentityInterface $user): bool
    {
        return $this->getOriginalIdentity($user) !== null;
    }

    private function getOriginalIdentity(?IdentityInterface $user): mixed
    {
        if ($user === null) {
            return null;
        }

        return $user->getOriginalData();
    }
}
