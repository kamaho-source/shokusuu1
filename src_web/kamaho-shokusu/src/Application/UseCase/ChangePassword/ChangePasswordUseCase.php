<?php
declare(strict_types=1);

namespace App\Application\UseCase\ChangePassword;

use App\Application\Port\AuditLogInterface;
use App\Domain\Repository\UserAccountRepositoryInterface;
use App\Domain\Service\PasswordRule;

/**
 * パスワードを変える（管理者が他の人のを変える、または本人が自分のを変える）。
 * 管理者が変えられる相手は、削除されていない人だけ。
 */
final class ChangePasswordUseCase
{
    public function __construct(
        private readonly UserAccountRepositoryInterface $users,
        private readonly AuditLogInterface $auditLog,
    ) {}

    /**
     * 管理者がパスワードを変える相手として選べる人。
     *
     * @return array<int, string> 利用者ID => 氏名
     */
    public function selectableUsers(): array
    {
        return $this->users->activeUserNames();
    }

    /**
     * @throws \Throwable DB の失敗（変換せずにそのまま投げる）
     */
    public function execute(ChangePasswordInput $input): ChangePasswordOutput
    {
        $user = $input->userId === null ? null : $this->users->find($input->userId);
        if ($input->byAdmin && ($user === null || $user->delFlag !== 0)) {
            return ChangePasswordOutput::rejected('ユーザーを選択してください。');
        }
        $error = PasswordRule::changeError($input->newPassword, $input->confirmPassword, countBytes: $input->byAdmin);
        if ($error !== null) {
            return ChangePasswordOutput::rejected($error);
        }
        if ($user === null || !$this->users->changePassword($user->id, $input->newPassword)) {
            return ChangePasswordOutput::saveFailed();
        }

        $this->auditLog->record(
            'user',
            $input->byAdmin ? 'user_password_change_admin' : 'user_password_change_self',
            $input->actor,
            'm_user_info',
            (string)$user->id,
            $input->byAdmin ? ['target_user_name' => $user->name] : null,
            true,
        );

        return ChangePasswordOutput::changed();
    }
}
