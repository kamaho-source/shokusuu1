<?php
declare(strict_types=1);

namespace App\Policy;

use Authorization\IdentityInterface;

/**
 * LP画像管理のアクセス制御ポリシー
 *
 * 全アクションとも管理者（i_admin = 1 or 3）のみ許可する。
 */
class MLpImagePolicy
{
    public function canIndex(?IdentityInterface $user, \App\Model\Entity\MLpImage $resource): bool
    {
        return $this->isAdmin($user);
    }

    public function canAdd(?IdentityInterface $user, \App\Model\Entity\MLpImage $resource): bool
    {
        return $this->isAdmin($user);
    }

    public function canEdit(?IdentityInterface $user, \App\Model\Entity\MLpImage $resource): bool
    {
        return $this->isAdmin($user);
    }

    public function canDelete(?IdentityInterface $user, \App\Model\Entity\MLpImage $resource): bool
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

        return false;
    }
}
