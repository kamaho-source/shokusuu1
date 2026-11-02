<?php
declare(strict_types=1);

namespace App\Application\UseCase\ExportMealSummary;

/**
 * 控除表の出力の結果。
 */
final class ExportMealSummaryOutput
{
    /**
     * @param list<array{name: string, staff_id: int|string|null, meal_counts: array{bento: int, morning: int, lunch: int, dinner: int}, total_price: int}> $rows 職員ごとの行
     */
    public function __construct(
        public readonly array $rows,
    ) {}
}
