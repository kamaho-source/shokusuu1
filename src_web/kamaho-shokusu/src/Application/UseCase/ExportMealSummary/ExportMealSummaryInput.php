<?php
declare(strict_types=1);

namespace App\Application\UseCase\ExportMealSummary;

use App\Application\Port\AuditActor;

/**
 * 控除表の出力の入力。
 */
final class ExportMealSummaryInput
{
    public function __construct(
        public readonly int $year,
        public readonly int $month,
        public readonly AuditActor $actor,
    ) {}
}
