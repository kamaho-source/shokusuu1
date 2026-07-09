<?php
declare(strict_types=1);

namespace App\Policy;

use App\Domain\ValueObject\UserRole;
use Authorization\IdentityInterface;

/**
 * 統計AI画面のアクセスポリシー。管理者（ADMIN / SYSTEM_ADMIN）のみ許可する。
 */
class StatsAiPolicy
{
    /**
     * 統計AI画面の表示を許可するか。
     */
    public function canIndex(IdentityInterface $user, $resource): bool
    {
        return $this->isAdmin($user);
    }

    /**
     * 統計AIへのストリーミング質問を許可するか。
     */
    public function canAskStream(IdentityInterface $user, $resource): bool
    {
        return $this->isAdmin($user);
    }

    private function isAdmin(IdentityInterface $user): bool
    {
        return UserRole::isAdmin((int)($user->get('i_admin') ?? 0));
    }
}
