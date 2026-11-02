<?php
declare(strict_types=1);

namespace App\Application\Query\Dto;

/**
 * ある日の、食事ごとの食べる人数。
 */
final class DayMealCounts
{
    /**
     * @param array<int, int> $counts 食事（1:朝 2:昼 3:夜 4:弁当）=> 人数
     */
    public function __construct(
        public readonly string $date,
        public readonly array $counts,
    ) {}

    /**
     * 画面（JS）に渡す形（従来どおり、食事 => 人数）。
     *
     * @return array<int, int>
     */
    public function toArray(): array
    {
        return $this->counts;
    }
}
