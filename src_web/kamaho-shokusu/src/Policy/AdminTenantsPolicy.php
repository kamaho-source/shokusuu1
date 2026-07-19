<?php
declare(strict_types=1);

namespace App\Policy;

use App\Domain\ValueObject\UserRole;
use Authorization\IdentityInterface;

/**
 * テナント管理用認可ポリシー（システム管理者専用）
 */
class AdminTenantsPolicy
{
    public function canIndex(?IdentityInterface $user, mixed $resource): bool
    {
        return $this->isSystemAdmin($user);
    }

    public function canAdd(?IdentityInterface $user, mixed $resource): bool
    {
        return $this->isSystemAdmin($user);
    }

    public function canUpdateStatus(?IdentityInterface $user, mixed $resource): bool
    {
        return $this->isSystemAdmin($user);
    }

    private function isSystemAdmin(?IdentityInterface $user): bool
    {
        if ($user === null) {
            return false;
        }
        $identity = $user->getOriginalData();
        if ($identity === null) {
            return false;
        }
        if (is_object($identity) && method_exists($identity, 'get')) {
            return UserRole::isSystemAdmin((int)$identity->get('i_admin'));
        }
        if (is_array($identity)) {
            return UserRole::isSystemAdmin((int)($identity['i_admin'] ?? 0));
        }
        return false;
    }
}
