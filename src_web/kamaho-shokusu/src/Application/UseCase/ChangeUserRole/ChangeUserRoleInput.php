<?php
declare(strict_types=1);

namespace App\Application\UseCase\ChangeUserRole;

use App\Application\Port\AuditActor;

/**
 * 利用者の権限（i_admin）を変える依頼。
 */
final class ChangeUserRoleInput
{
    /**
     * @param int  $newRole          新しい権限（0 は解除）
     * @param int  $offTargetRole    解除の操作が対象とする権限（管理者の切替なら 1、ブロック長なら 2、システム管理者なら 3）
     * @param bool $overwrite        画面で「別の権限から変更しますか？」に同意したか
     * @param bool $allowSystemAdmin システム管理者（3）を付けてよい画面からの依頼か
     */
    public function __construct(
        public readonly int $targetUserId,
        public readonly int $newRole,
        public readonly int $offTargetRole,
        public readonly bool $overwrite,
        public readonly bool $allowSystemAdmin,
        public readonly AuditActor $actor,
    ) {}
}
