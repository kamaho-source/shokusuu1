<?php
declare(strict_types=1);

namespace App\Application\Port;

/**
 * 監査ログに残す操作者。
 */
final class AuditActor
{
    public function __construct(
        public readonly string $name,
        public readonly int $id,
        public readonly string $loginId,
        public readonly string $ipAddress,
    ) {}
}
