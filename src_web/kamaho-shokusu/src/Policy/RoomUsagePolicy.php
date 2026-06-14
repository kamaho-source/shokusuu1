<?php
declare(strict_types=1);

namespace App\Policy;

use App\Domain\ValueObject\UserRole;
use Authorization\IdentityInterface;

/**
 * 部屋使用率 API 用認可ポリシー
 *
 * システム管理者（i_admin === 3）のみアクセスを許可する。
 */
class RoomUsagePolicy
{
    public function canIndex(?IdentityInterface $user, mixed $resource): bool
    {
        return $this->isSystemAdmin($user);
    }

    public function canRoomUsage(?IdentityInterface $user, mixed $resource): bool
    {
        return $this->isSystemAdmin($user);
    }

    public function canLowUsageRooms(?IdentityInterface $user, mixed $resource): bool
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
        if ($identity instanceof \ArrayAccess) {
            return UserRole::isSystemAdmin((int)($identity['i_admin'] ?? 0));
        }
        return false;
    }
}
