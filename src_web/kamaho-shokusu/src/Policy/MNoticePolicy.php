<?php
declare(strict_types=1);

namespace App\Policy;

use Authorization\IdentityInterface;

class MNoticePolicy
{
    /**
     * @param IdentityInterface|null $user
     * @param \App\Model\Entity\MNotice $resource
     * @return bool
     */
    public function canIndex(?IdentityInterface $user, \App\Model\Entity\MNotice $resource): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * @param IdentityInterface|null $user
     * @param \App\Model\Entity\MNotice $resource
     * @return bool
     */
    public function canAdd(?IdentityInterface $user, \App\Model\Entity\MNotice $resource): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * @param IdentityInterface|null $user
     * @param \App\Model\Entity\MNotice $resource
     * @return bool
     */
    public function canEdit(?IdentityInterface $user, \App\Model\Entity\MNotice $resource): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * @param IdentityInterface|null $user
     * @param \App\Model\Entity\MNotice $resource
     * @return bool
     */
    public function canDelete(?IdentityInterface $user, \App\Model\Entity\MNotice $resource): bool
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
}
