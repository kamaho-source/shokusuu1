<?php
declare(strict_types=1);

namespace App\Policy;

use Authorization\IdentityInterface;

class MRoomTransferSchedulePolicy
{
    public function canIndex(?IdentityInterface $user, mixed $resource): bool
    {
        return $this->isAdmin($user);
    }

    public function canAdd(?IdentityInterface $user, mixed $resource): bool
    {
        return $this->isAdmin($user);
    }

    public function canCancel(?IdentityInterface $user, mixed $resource): bool
    {
        return $this->isAdmin($user);
    }

    private function isAdmin(?IdentityInterface $user): bool
    {
        if ($user === null) {
            return false;
        }

        $identity = $user->getOriginalData();
        if ($identity === null) {
            return false;
        }

        if (is_object($identity) && method_exists($identity, 'get')) {
            return (int)$identity->get('i_admin') === 1;
        }

        if (is_array($identity)) {
            return (int)($identity['i_admin'] ?? 0) === 1;
        }

        return false;
    }
}
