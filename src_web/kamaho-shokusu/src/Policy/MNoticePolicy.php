<?php
declare(strict_types=1);

namespace App\Policy;

use Authorization\IdentityInterface;

class MNoticePolicy
{
    use PolicyTrait;

    public function canIndex(?IdentityInterface $user, \App\Model\Entity\MNotice $resource): bool
    {
        return $this->isAdminOrSystemAdmin($user);
    }

    public function canAdd(?IdentityInterface $user, \App\Model\Entity\MNotice $resource): bool
    {
        return $this->isAdminOrSystemAdmin($user);
    }

    public function canEdit(?IdentityInterface $user, \App\Model\Entity\MNotice $resource): bool
    {
        return $this->isAdminOrSystemAdmin($user) && $this->isSameTenant($user, $resource);
    }

    public function canDelete(?IdentityInterface $user, \App\Model\Entity\MNotice $resource): bool
    {
        return $this->isAdminOrSystemAdmin($user) && $this->isSameTenant($user, $resource);
    }
}
