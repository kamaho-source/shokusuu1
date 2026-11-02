<?php
declare(strict_types=1);

namespace App\Application\Query\Dto;

/**
 * 職員1人の、月の食数。
 */
final class StaffMealCount
{
    /**
     * @param int|string|null $staffId 職員ID（i_id_staff）
     * @param array{bento: int, morning: int, lunch: int, dinner: int} $counts
     */
    public function __construct(
        public readonly string $name,
        public readonly int|string|null $staffId,
        public readonly array $counts,
    ) {}
}
