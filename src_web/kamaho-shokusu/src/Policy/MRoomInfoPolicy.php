<?php
declare(strict_types=1);

namespace App\Policy;

use App\Model\Entity\MRoomInfo;
use Authorization\IdentityInterface;

class MRoomInfoPolicy
{
    use PolicyTrait;

    public function canIndex(?IdentityInterface $user, MRoomInfo $resource): bool
    {
        return $this->isAuthenticated($user);
    }

    public function canView(?IdentityInterface $user, MRoomInfo $resource): bool
    {
        return $this->isAuthenticated($user) && $this->isSameTenant($user, $resource);
    }

    public function canAdd(?IdentityInterface $user, MRoomInfo $resource): bool
    {
        return $this->isAdmin($user);
    }

    public function canEdit(?IdentityInterface $user, MRoomInfo $resource): bool
    {
        return $this->isAdmin($user) && $this->isSameTenant($user, $resource);
    }

    public function canDelete(?IdentityInterface $user, MRoomInfo $resource): bool
    {
        return $this->isAdmin($user) && $this->isSameTenant($user, $resource);
    }
}
