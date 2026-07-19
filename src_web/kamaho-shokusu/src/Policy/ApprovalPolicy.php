<?php
declare(strict_types=1);

namespace App\Policy;

use App\Domain\ValueObject\UserRole;

use App\Service\RoomAccessService;
use Authorization\IdentityInterface;

/**
 * 承認画面のアクセス制御ポリシー
 *
 * ブロック長（i_admin = 2）と管理者（i_admin = 1）のみアクセス可能。
 */
class ApprovalPolicy
{
    private RoomAccessService $roomAccessService;

    public function __construct(?RoomAccessService $roomAccessService = null)
    {
        $this->roomAccessService = $roomAccessService ?? new RoomAccessService();
    }

    /** ブロック長用承認一覧 */
    public function canBlockLeaderIndex(?IdentityInterface $user, \App\Controller\ApprovalController $resource): bool
    {
        return $this->isBlockLeaderOrAdmin($user);
    }

    /** ブロック長による承認操作 */
    public function canBlockLeaderApprove(?IdentityInterface $user, \App\Controller\ApprovalController $resource): bool
    {
        return $this->isBlockLeaderOrAdmin($user);
    }

    /** ブロック長による差し戻し操作 */
    public function canBlockLeaderReject(?IdentityInterface $user, \App\Controller\ApprovalController $resource): bool
    {
        return $this->isBlockLeaderOrAdmin($user);
    }

    /** 管理者用承認一覧 */
    public function canAdminIndex(?IdentityInterface $user, \App\Controller\ApprovalController $resource): bool
    {
        return $this->isAdmin($user);
    }

    /** 管理者による最終承認 */
    public function canAdminApprove(?IdentityInterface $user, \App\Controller\ApprovalController $resource): bool
    {
        return $this->isAdmin($user);
    }

    /** 管理者による差し戻し */
    public function canAdminReject(?IdentityInterface $user, \App\Controller\ApprovalController $resource): bool
    {
        return $this->isAdmin($user);
    }

    /** 承認済みレコードを t_reservation_info へ反映 */
    public function canAdminReflect(?IdentityInterface $user, \App\Controller\ApprovalController $resource): bool
    {
        return $this->isAdmin($user);
    }

    // ------------------------------------------------------------------
    // helpers
    // ------------------------------------------------------------------

    private function isAdmin(?IdentityInterface $user): bool
    {
        $identity = $this->getOriginalIdentity($user);
        if ($identity === null) {
            return false;
        }

        if (is_object($identity) && method_exists($identity, 'get')) {
            return UserRole::isAdmin((int)$identity->get('i_admin'));
        }

        if (is_array($identity)) {
            return UserRole::isAdmin((int)($identity['i_admin'] ?? 0));
        }

        if ($identity instanceof \ArrayAccess) {
            return UserRole::isAdmin((int)($identity['i_admin'] ?? 0));
        }

        return false;
    }

    private function isBlockLeader(?IdentityInterface $user): bool
    {
        $identity = $this->getOriginalIdentity($user);
        if ($identity === null) {
            return false;
        }

        if (is_object($identity) && method_exists($identity, 'get')) {
            return UserRole::isBlockLeader((int)$identity->get('i_admin'));
        }

        if (is_array($identity)) {
            return UserRole::isBlockLeader((int)($identity['i_admin'] ?? 0));
        }

        if ($identity instanceof \ArrayAccess) {
            return UserRole::isBlockLeader((int)($identity['i_admin'] ?? 0));
        }

        return false;
    }

    private function isBlockLeaderOrAdmin(?IdentityInterface $user): bool
    {
        return $this->isAdmin($user) || $this->isBlockLeader($user);
    }

    private function getUserId(?IdentityInterface $user): int
    {
        $identity = $this->getOriginalIdentity($user);
        if ($identity === null) {
            return 0;
        }

        if (is_object($identity) && method_exists($identity, 'get')) {
            return (int)$identity->get('i_id_user');
        }

        if (is_array($identity)) {
            return (int)($identity['i_id_user'] ?? 0);
        }

        if ($identity instanceof \ArrayAccess) {
            return (int)($identity['i_id_user'] ?? 0);
        }

        return 0;
    }

    private function getOriginalIdentity(?IdentityInterface $user): object|array|null
    {
        if ($user === null) {
            return null;
        }

        return $user->getOriginalData();
    }
}
