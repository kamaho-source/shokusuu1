<?php
declare(strict_types=1);

namespace App\Policy;

use App\Model\Entity\MMealPriceInfo;
use Authorization\IdentityInterface;

class MMealPriceInfoPolicy
{
    public function canIndex(?IdentityInterface $user, MMealPriceInfo $resource): bool
    {
        return $this->isAuthenticated($user);
    }

    public function canView(?IdentityInterface $user, MMealPriceInfo $resource): bool
    {
        return $this->isAuthenticated($user);
    }

    public function canAdd(?IdentityInterface $user, MMealPriceInfo $resource): bool
    {
        return $this->isAdmin($user);
    }

    public function canEdit(?IdentityInterface $user, MMealPriceInfo $resource): bool
    {
        return $this->isAdmin($user);
    }

    public function canDelete(?IdentityInterface $user, MMealPriceInfo $resource): bool
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
            return in_array((int)$identity->get('i_admin'), [1, 3]);
        }

        if (is_array($identity)) {
            return in_array((int)($identity['i_admin'] ?? 0), [1, 3]);
        }

        if ($identity instanceof \ArrayAccess) {
            return in_array((int)($identity['i_admin'] ?? 0), [1, 3]);
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
