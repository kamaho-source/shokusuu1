<?php
declare(strict_types=1);

namespace App\Application\Query\Dto;

/**
 * システムレポートの「集計から除く人」の候補1人。
 */
final class ReportUserOption
{
    public function __construct(
        public readonly int $userId,
        public readonly string $userName,
        public readonly bool $isChild,
    ) {}
}
