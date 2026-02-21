<?php
declare(strict_types=1);

namespace App\Policy;

use App\Model\Entity\MUserInfo;
use Authorization\IdentityInterface;

class MUserInfoPolicy
{
    public function canIndex(?IdentityInterface $user, MUserInfo $resource): bool
    {
        return $this->getOriginalIdentity($user) !== null;
    }

    public function canView(?IdentityInterface $user, MUserInfo $resource): bool
    {
        return $this->isAdmin($user) || $this->isOwner($user, $resource);
    }

    public function canAdd(?IdentityInterface $user, MUserInfo $resource): bool
    {
        return $this->isAdmin($user);
    }

    public function canImport(?IdentityInterface $user, MUserInfo $resource): bool
    {
        return $this->isAdmin($user);
    }

    public function canImportForm(?IdentityInterface $user, MUserInfo $resource): bool
    {
        return $this->isAdmin($user);
    }

    public function canImportJson(?IdentityInterface $user, MUserInfo $resource): bool
    {
        return $this->isAdmin($user);
    }

    public function canAddRoomToUser(?IdentityInterface $user, MUserInfo $resource): bool
    {
        return $this->isAdmin($user);
    }

    public function canRemoveRoomFromUser(?IdentityInterface $user, MUserInfo $resource): bool
    {
        return $this->isAdmin($user);
    }

    public function canAdminChangePassword(?IdentityInterface $user, MUserInfo $resource): bool
    {
        return $this->isAdmin($user);
    }

    public function canAddUserRooms(?IdentityInterface $user, MUserInfo $resource): bool
    {
        return $this->isAdmin($user);
    }

    public function canGeneralPasswordReset(?IdentityInterface $user, MUserInfo $resource): bool
    {
        return $this->isOwner($user, $resource) || $this->isAdmin($user);
    }

    public function canEdit(?IdentityInterface $user, MUserInfo $resource): bool
    {
        return $this->isAdmin($user) || $this->isOwner($user, $resource);
    }

    public function canDelete(?IdentityInterface $user, MUserInfo $resource): bool
    {
        return $this->isAdmin($user);
    }

    public function canUpdateAdminStatus(?IdentityInterface $user, MUserInfo $resource): bool
    {
        return $this->isAdmin($user);
    }

    public function canRestore(?IdentityInterface $user, MUserInfo $resource): bool
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

    private function isOwner(?IdentityInterface $user, MUserInfo $resource): bool
    {
        $identity = $this->getOriginalIdentity($user);
        if ($identity === null) {
            return false;
        }

        $identityId = null;
        if (is_object($identity) && method_exists($identity, 'get')) {
            $identityId = (int)$identity->get('i_id_user');
        } elseif (is_array($identity)) {
            $identityId = (int)($identity['i_id_user'] ?? 0);
        } elseif ($identity instanceof \ArrayAccess) {
            $identityId = (int)($identity['i_id_user'] ?? 0);
        }

        return $identityId !== null && $identityId > 0 && $identityId === (int)$resource->i_id_user;
    }

    private function getOriginalIdentity(?IdentityInterface $user): mixed
    {
        if ($user === null) {
            return null;
        }

        return $user->getOriginalData();
    }
}
