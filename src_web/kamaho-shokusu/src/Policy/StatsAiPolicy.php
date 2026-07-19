<?php
declare(strict_types=1);

namespace App\Policy;

use App\Domain\ValueObject\UserRole;
use Authorization\IdentityInterface;

/**
 * 統計AI画面のアクセスポリシー。システム管理者（SYSTEM_ADMIN）のみ許可する。
 */
final class StatsAiPolicy
{
    /**
     * 統計AI画面の表示を許可するか。
     */
    public function canIndex(IdentityInterface $user, $resource): bool
    {
        return $this->isSystemAdmin($user);
    }

    /**
     * 統計AIへのストリーミング質問を許可するか。
     */
    public function canAskStream(IdentityInterface $user, $resource): bool
    {
        return $this->isSystemAdmin($user);
    }

    private function isSystemAdmin(IdentityInterface $user): bool
    {
        return UserRole::isSystemAdmin((int)($user->get('i_admin') ?? 0));
    }
}
