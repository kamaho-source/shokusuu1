<?php
declare(strict_types=1);

namespace App\Application\UseCase\UpdateNotice;

use App\Application\Port\AuditActor;

/**
 * お知らせの更新の Input DTO。
 */
final class UpdateNoticeInput
{
    public function __construct(
        public readonly int $id,
        public readonly string $title,
        public readonly ?string $body,
        public readonly ?string $startDate,
        public readonly ?string $endDate,
        public readonly int $importance,
        public readonly int $type,
        public readonly bool $isSystemAdmin,
        public readonly AuditActor $actor,
        public readonly bool $modal = false,
    ) {}
}
