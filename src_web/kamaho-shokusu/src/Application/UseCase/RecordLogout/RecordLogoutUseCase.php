<?php
declare(strict_types=1);

namespace App\Application\UseCase\RecordLogout;

use App\Application\Port\AuditActor;
use App\Application\Port\AuditLogInterface;

/**
 * ログアウトを監査ログ（user_logout）に残す。
 */
final class RecordLogoutUseCase
{
    public function __construct(private readonly AuditLogInterface $auditLog) {}

    public function execute(RecordLogoutInput $input): void
    {
        $this->auditLog->record(
            'user',
            'user_logout',
            new AuditActor($input->userName ?? '不明', $input->userId, $input->loginAccount ?? '', $input->ipAddress),
            'm_user_info',
            (string)$input->userId,
            null,
            true,
        );
    }
}
