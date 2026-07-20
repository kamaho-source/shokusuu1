<?php
declare(strict_types=1);

namespace App\Policy;

use App\Model\Entity\MMealPriceInfo;
use Authorization\IdentityInterface;

class MMealPriceInfoPolicy
{
    use PolicyTrait;

    public function canIndex(?IdentityInterface $user, MMealPriceInfo $resource): bool
    {
        return $this->isAuthenticated($user);
    }

    public function canView(?IdentityInterface $user, MMealPriceInfo $resource): bool
    {
        return $this->isAuthenticated($user) && $this->isSameTenant($user, $resource);
    }

    public function canAdd(?IdentityInterface $user, MMealPriceInfo $resource): bool
    {
        return $this->isAdmin($user);
    }

    public function canEdit(?IdentityInterface $user, MMealPriceInfo $resource): bool
    {
        return $this->isAdmin($user) && $this->isSameTenant($user, $resource);
    }

    public function canDelete(?IdentityInterface $user, MMealPriceInfo $resource): bool
    {
        return $this->isAdmin($user) && $this->isSameTenant($user, $resource);
    }
}
