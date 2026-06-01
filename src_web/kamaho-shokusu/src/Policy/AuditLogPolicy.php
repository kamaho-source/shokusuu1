<?php
declare(strict_types=1);

namespace App\Policy;

use Authorization\IdentityInterface;

/**
 * 監査ログ用認可ポリシー
 *
 * システム管理者（i_admin === 3）のみアクセスを許可する。
 */
class AuditLogPolicy
{
    public function canIndex(?IdentityInterface $user, mixed $resource): bool
    {
        return $this->isSystemAdmin($user);
    }

    public function canExport(?IdentityInterface $user, mixed $resource): bool
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
            return (int)$identity->get('i_admin') === 3;
        }
        if (is_array($identity)) {
            return (int)($identity['i_admin'] ?? 0) === 3;
        }
        if ($identity instanceof \ArrayAccess) {
            return (int)($identity['i_admin'] ?? 0) === 3;
        }
        return false;
    }
}
