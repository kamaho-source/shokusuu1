<?php
declare(strict_types=1);

namespace App\Application\UseCase\RecordLoginFailure;

use App\Application\Port\AuditActor;
use App\Application\Port\AuditLogInterface;
use App\Application\Query\UserQueryInterface;

/**
 * ログインの失敗を監査ログ（user_login_failed）に残す。
 * 操作者は、入力されたログインIDのアカウント（無ければ「該当アカウントなし」）。
 */
final class RecordLoginFailureUseCase
{
    public const FAILURE_REASON = 'ユーザー名またはパスワードが一致しません';

    public function __construct(
        private readonly UserQueryInterface $users,
        private readonly AuditLogInterface $auditLog,
    ) {}

    public function execute(RecordLoginFailureInput $input): void
    {
        $account = $input->loginAccount === '' ? null : $this->users->findByLoginAccount($input->loginAccount);

        $this->auditLog->record(
            'user',
            'user_login_failed',
            new AuditActor(
                $account !== null ? (string)$account->name : AuditActor::UNKNOWN_ACCOUNT_NAME,
                $account !== null ? $account->id : 0,
                $input->loginAccount,
                $input->ipAddress,
            ),
            'm_user_info',
            null,
            ['login_account' => $input->loginAccount],
            false,
            self::FAILURE_REASON,
        );
    }
}
