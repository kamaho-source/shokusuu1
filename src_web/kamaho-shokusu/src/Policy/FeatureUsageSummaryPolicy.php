<?php
declare(strict_types=1);

namespace App\Policy;

use Authorization\IdentityInterface;

/**
 * 機能使用頻度ダッシュボード認可ポリシー
 *
 * システム管理者（i_admin === 3）のみアクセスを許可する。
 */
class FeatureUsageSummaryPolicy
{
    use PolicyTrait;

    public function canIndex(?IdentityInterface $user, mixed $resource): bool
    {
        return $this->isSystemAdmin($user);
    }
}
