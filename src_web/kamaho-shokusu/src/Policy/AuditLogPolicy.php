<?php
declare(strict_types=1);

namespace App\Policy;

use Authorization\IdentityInterface;

/**
 * 監査ログ用認可ポリシー
 *
 * - システム管理者（platform_admin）: 全テナントのログを閲覧可能
 * - テナント管理者（tenant_admin）: 自テナントのログのみ閲覧可能（クエリ層で tenant_id を強制）
 *
 * リソースは Controller のため、テナント境界チェックはクエリ層に委ねる。
 */
class AuditLogPolicy
{
    use PolicyTrait;

    public function canIndex(?IdentityInterface $user, \App\Controller\AuditLogController $resource): bool
    {
        return $this->isSystemAdmin($user) || $this->isTenantAdmin($user);
    }

    public function canExport(?IdentityInterface $user, \App\Controller\AuditLogController $resource): bool
    {
        return $this->isSystemAdmin($user) || $this->isTenantAdmin($user);
    }
}
