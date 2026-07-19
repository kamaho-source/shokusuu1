<?php
declare(strict_types=1);

namespace App\Policy;

use Authorization\IdentityInterface;

class MRoomTransferSchedulePolicy
{
    use PolicyTrait;

    public function canIndex(?IdentityInterface $user, \App\Model\Entity\MRoomTransferSchedule $resource): bool
    {
        return $this->isAdmin($user);
    }

    public function canAdd(?IdentityInterface $user, \App\Model\Entity\MRoomTransferSchedule $resource): bool
    {
        return $this->isAdmin($user);
    }

    public function canCancel(?IdentityInterface $user, \App\Model\Entity\MRoomTransferSchedule $resource): bool
    {
        return $this->isAdmin($user) && $this->isSameTenant($user, $resource);
    }
}
