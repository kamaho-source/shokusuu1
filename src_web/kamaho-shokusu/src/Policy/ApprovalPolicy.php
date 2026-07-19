<?php
declare(strict_types=1);

namespace App\Policy;

use App\Service\RoomAccessService;
use Authorization\IdentityInterface;

/**
 * 承認画面のアクセス制御ポリシー
 *
 * ブロック長（i_admin = 2）と管理者（i_admin = 1）のみアクセス可能。
 * リソースは Controller のため、テナント境界チェックはクエリ層に委ねる。
 */
class ApprovalPolicy
{
    use PolicyTrait;

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
}
