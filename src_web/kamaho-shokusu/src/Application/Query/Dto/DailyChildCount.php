<?php
declare(strict_types=1);

namespace App\Application\Query\Dto;

/**
 * ある日の、子どもの予約数。
 */
final class DailyChildCount
{
    public function __construct(
        public readonly string $date,
        public readonly int $childCount,
    ) {}

    /**
     * @return array{date: string, child_count: int} API の応答の形
     */
    public function toArray(): array
    {
        return ['date' => $this->date, 'child_count' => $this->childCount];
    }
}
