<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\Fake;

use App\Application\Port\AuditActor;
use App\Application\Port\AuditLogInterface;

/**
 * AuditLogInterface の Spy（書いた内容を覚えておく）。
 */
final class SpyAuditLog implements AuditLogInterface
{
    /** @var list<array<string, mixed>> */
    public array $records = [];

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
        $this->records[] = compact('category', 'action', 'actor', 'targetTable', 'targetId', 'detail', 'succeeded', 'failureReason');
    }
}
