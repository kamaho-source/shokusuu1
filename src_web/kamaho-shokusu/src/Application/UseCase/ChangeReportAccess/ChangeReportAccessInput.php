<?php
declare(strict_types=1);

namespace App\Application\UseCase\ChangeReportAccess;

use App\Application\Port\AuditActor;

/**
 * 利用者のレポート閲覧権を変える依頼。
 */
final class ChangeReportAccessInput
{
    public function __construct(
        public readonly int $targetUserId,
        public readonly bool $enabled,
        public readonly AuditActor $actor,
    ) {}
}
