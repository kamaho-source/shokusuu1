<?php
declare(strict_types=1);

namespace App\Policy;

use Authorization\IdentityInterface;

/**
 * 統計AI画面のアクセスポリシー。システム管理者（SYSTEM_ADMIN）のみ許可する。
 */
final class StatsAiPolicy
{
    use PolicyTrait;

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
}
