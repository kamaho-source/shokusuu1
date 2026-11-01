<?php
declare(strict_types=1);

namespace App\Infrastructure\Log;

use App\Application\Port\AuditActor;
use App\Application\Port\AuditLogInterface;
use App\Service\AuditLogService;

/**
 * AuditLogInterface の実装。既存の AuditLogService（t_audit_log への書き込み）に渡す。
 */
final class AuditLogAdapter implements AuditLogInterface
{
    public function record(
        string $category,
        string $action,
        AuditActor $actor,
        ?string $targetTable,
        ?string $targetId,
        ?array $detail,
        bool $succeeded,
        ?string $failureReason = null,
    ): void {
        AuditLogService::record(
            $category,
            $action,
            $actor->name,
            $actor->id,
            $targetTable,
            $targetId,
            $detail,
            $actor->ipAddress ?: null,
            $succeeded ? 1 : 0,
            $actor->loginId,
            $failureReason
        );
    }
}
