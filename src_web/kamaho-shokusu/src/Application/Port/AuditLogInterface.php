<?php
declare(strict_types=1);

namespace App\Application\Port;

/**
 * 監査ログを書く。書けなくても例外にしない（業務の処理を止めない）。
 */
interface AuditLogInterface
{
    /**
     * @param array<string, mixed>|null $detail
     * @param string|null $failureReason 失敗（$succeeded=false）のときの理由
     */
    public function record(
        string $category,
        string $action,
        AuditActor $actor,
        ?string $targetTable,
        ?string $targetId,
        ?array $detail,
        bool $succeeded,
        ?string $failureReason = null,
    ): void;
}
