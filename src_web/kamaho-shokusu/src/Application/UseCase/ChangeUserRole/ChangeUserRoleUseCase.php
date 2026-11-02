<?php
declare(strict_types=1);

namespace App\Application\UseCase\ChangeUserRole;

use App\Application\Port\AuditLogInterface;
use App\Domain\Exception\NotFoundException;
use App\Domain\Repository\UserAccountRepositoryInterface;
use App\Domain\Service\UserRoleRule;

/**
 * 利用者の権限（管理者・ブロック長・システム管理者）を変える。
 *
 * 誰が変えてよいか（認可）は呼び出し側（Policy）で確かめる。ここでは値と競合を確かめ、保存して監査ログに残す。
 */
final class ChangeUserRoleUseCase
{
    private const AUDIT_ACTION = 'user_permission_change';

    public function __construct(
        private readonly UserAccountRepositoryInterface $users,
        private readonly AuditLogInterface $auditLog,
    ) {}

    /** 対象の利用者が居るか（認可より先に 404 を返すため）。 */
    public function targetExists(int $userId): bool
    {
        return $this->users->find($userId) !== null;
    }

    /**
     * @throws NotFoundException 対象の利用者が居ない場合
     * @throws \Throwable DB の失敗（変換せずにそのまま投げる）
     */
    public function execute(ChangeUserRoleInput $input): ChangeUserRoleOutput
    {
        $user = $this->users->find($input->targetUserId);
        if ($user === null) {
            throw new NotFoundException('対象ユーザーが見つかりません。');
        }
        $detail = [
            'target_user_name' => $user->name,
            'old_i_admin'      => $user->role,
            'new_i_admin'      => $input->newRole,
        ];

        // 付けてはいけない値（システム管理者を一般の画面から付ける、範囲外の値）は、権限昇格の疑いとして記録する
        if (!UserRoleRule::isAssignable($input->newRole, $input->allowSystemAdmin)) {
            $this->auditLog->record(
                'user', self::AUDIT_ACTION, $input->actor, 'm_user_info', (string)$user->id, $detail, false,
                '許可されていない権限値のため変更を拒否しました（不正値・権限昇格の疑い）'
            );

            return ChangeUserRoleOutput::notAssignable();
        }

        $conflict = UserRoleRule::conflict((int)$user->role, $input->newRole, $input->offTargetRole, $input->overwrite);
        if ($conflict !== null) {
            return ChangeUserRoleOutput::conflict($conflict);
        }

        $saved = $this->users->updateRole($user->id, $input->newRole, $input->actor->name);
        $this->auditLog->record(
            'user', self::AUDIT_ACTION, $input->actor, 'm_user_info', (string)$user->id, $detail, $saved,
            $saved ? null : '権限変更の保存に失敗しました'
        );

        return $saved ? ChangeUserRoleOutput::changed() : ChangeUserRoleOutput::saveFailed();
    }
}
