<?php
declare(strict_types=1);

namespace App\Application\UseCase\CreateNotice;

use App\Application\Port\AuditActor;

/**
 * お知らせの登録の Input DTO。
 */
final class CreateNoticeInput
{
    /**
     * @param string|null $startDate 掲載開始日（空は null）
     * @param string|null $endDate 掲載終了日（空は null）
     */
    public function __construct(
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
