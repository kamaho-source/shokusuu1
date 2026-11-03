<?php
declare(strict_types=1);

namespace App\Application\UseCase\RecordLogin;

use App\Application\Port\AuditActor;
use App\Application\Port\AuditLogInterface;

/**
 * ログインの成功を監査ログ（user_login）に残す。
 */
final class RecordLoginUseCase
{
    public function __construct(private readonly AuditLogInterface $auditLog) {}

    public function execute(RecordLoginInput $input): void
    {
        $this->auditLog->record(
            'user',
            'user_login',
            // 氏名が無ければログインID、それも無ければ「不明」
            new AuditActor($input->userName ?? $input->loginAccount ?? '不明', $input->userId, $input->loginAccount ?? '', $input->ipAddress),
            'm_user_info',
            (string)$input->userId,
            null,
            true,
        );
    }
}
