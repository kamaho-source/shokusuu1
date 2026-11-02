<?php
declare(strict_types=1);

namespace App\Application\Query\Dto;

/**
 * 食事ごとの食べる人数。
 */
final class MealTypeCount
{
    public function __construct(
        public readonly int $mealType,
        public readonly int $count,
    ) {}

    /**
     * @return array{meal_type: int, count: int} API の応答・キャッシュの形
     */
    public function toArray(): array
    {
        return ['meal_type' => $this->mealType, 'count' => $this->count];
    }

    /**
     * @param array{meal_type: int|string, count: int|string} $row
     */
    public static function fromArray(array $row): self
    {
        return new self((int)$row['meal_type'], (int)$row['count']);
    }
}
