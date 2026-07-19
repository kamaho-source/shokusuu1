<?php
declare(strict_types=1);

namespace App\Policy;

use App\Domain\ValueObject\UserRole;
use Authorization\IdentityInterface;
use Cake\ORM\Entity;

/**
 * 認可ポリシーの共通ヘルパーをまとめたTrait。
 * 全 Policy クラスで use して実装の重複を排除する。
 */
trait PolicyTrait
{
    protected function isAuthenticated(?IdentityInterface $user): bool
    {
        return $this->getOriginalIdentity($user) !== null;
    }

    protected function isAdmin(?IdentityInterface $user): bool
    {
        $identity = $this->getOriginalIdentity($user);
        if ($identity === null) {
            return false;
        }
        return UserRole::isAdmin((int)$this->extractField($identity, 'i_admin'));
    }

    protected function isBlockLeader(?IdentityInterface $user): bool
    {
        $identity = $this->getOriginalIdentity($user);
        if ($identity === null) {
            return false;
        }
        return UserRole::isBlockLeader((int)$this->extractField($identity, 'i_admin'));
    }

    protected function isSystemAdmin(?IdentityInterface $user): bool
    {
        $identity = $this->getOriginalIdentity($user);
        if ($identity === null) {
            return false;
        }
        return UserRole::isSystemAdmin((int)$this->extractField($identity, 'i_admin'));
    }

    /** テナント管理者（i_admin = 4）かどうかを返す。 */
    protected function isTenantAdmin(?IdentityInterface $user): bool
    {
        $identity = $this->getOriginalIdentity($user);
        if ($identity === null) {
            return false;
        }
        return UserRole::isTenantAdmin((int)$this->extractField($identity, 'i_admin'));
    }

    /**
     * 管理者またはシステム管理者（i_admin = 1 または 3）かどうかを返す。
     * TENANT_ADMIN(4) は isAdmin() に含まれるためここには不要だが後方互換のため残す。
     */
    protected function isAdminOrSystemAdmin(?IdentityInterface $user): bool
    {
        return $this->isAdmin($user) || $this->isSystemAdmin($user);
    }

    protected function isStaff(?IdentityInterface $user): bool
    {
        $identity = $this->getOriginalIdentity($user);
        if ($identity === null) {
            return false;
        }
        return in_array((int)$this->extractField($identity, 'i_user_level'), [0, 7], true);
    }

    protected function isStaffOrAdmin(?IdentityInterface $user): bool
    {
        return $this->isStaff($user) || $this->isAdmin($user);
    }

    protected function isBlockLeaderOrAdmin(?IdentityInterface $user): bool
    {
        return $this->isBlockLeader($user) || $this->isAdmin($user);
    }

    protected function hasStaffId(?IdentityInterface $user): bool
    {
        $identity = $this->getOriginalIdentity($user);
        if ($identity === null) {
            return false;
        }
        $staffId = $this->extractField($identity, 'i_id_staff');
        return $staffId !== null && $staffId !== '' && $staffId !== 0;
    }

    protected function getUserId(?IdentityInterface $user): int
    {
        $identity = $this->getOriginalIdentity($user);
        if ($identity === null) {
            return 0;
        }
        return (int)$this->extractField($identity, 'i_id_user');
    }

    /**
     * ログインユーザーのテナントIDを返す。未設定（移行期間）は null。
     */
    protected function getTenantId(?IdentityInterface $user): ?int
    {
        $identity = $this->getOriginalIdentity($user);
        if ($identity === null) {
            return null;
        }
        $value = $this->extractField($identity, 'tenant_id');
        return $value !== null ? (int)$value : null;
    }

    /**
     * リソースがログインユーザーと同じテナントに属するかを確認する。
     *
     * - SaaS システム管理者（i_admin = 3）は全テナントに横断アクセス可能
     * - ユーザーまたはリソースの tenant_id がどちらかでも null の場合は
     *   移行期間として許可する（既存データへの後方互換）
     */
    protected function isSameTenant(?IdentityInterface $user, object $resource): bool
    {
        $identity = $this->getOriginalIdentity($user);
        if ($identity !== null && UserRole::isSystemAdmin((int)$this->extractField($identity, 'i_admin'))) {
            return true;
        }

        $userTenantId = $this->getTenantId($user);
        if ($userTenantId === null) {
            return true;
        }

        $resourceTenantId = null;
        if ($resource instanceof Entity) {
            $resourceTenantId = $resource->get('tenant_id');
        } elseif (method_exists($resource, 'get')) {
            $resourceTenantId = $resource->get('tenant_id');
        }

        if ($resourceTenantId === null) {
            return true;
        }

        return (int)$resourceTenantId === $userTenantId;
    }

    protected function getOriginalIdentity(?IdentityInterface $user): object|array|null
    {
        if ($user === null) {
            return null;
        }
        return $user->getOriginalData();
    }

    private function extractField(object|array $identity, string $key): mixed
    {
        if (is_object($identity) && method_exists($identity, 'get')) {
            return $identity->get($key);
        }
        if (is_array($identity)) {
            return $identity[$key] ?? null;
        }
        if ($identity instanceof \ArrayAccess) {
            return $identity[$key] ?? null;
        }
        return null;
    }
}
