<?php
declare(strict_types=1);

namespace App\Policy;

use Authorization\IdentityInterface;

/**
 * 監査ログ用認可ポリシー
 *
 * システム管理者（i_admin === 3）のみアクセスを許可する。
 * リソースは Controller のため、テナント境界チェックはクエリ層に委ねる。
 */
class AuditLogPolicy
{
    use PolicyTrait;

    public function canIndex(?IdentityInterface $user, \App\Controller\AuditLogController $resource): bool
    {
        return $this->isSystemAdmin($user);
    }

    public function canExport(?IdentityInterface $user, \App\Controller\AuditLogController $resource): bool
    {
        return $this->isSystemAdmin($user);
    }
}
