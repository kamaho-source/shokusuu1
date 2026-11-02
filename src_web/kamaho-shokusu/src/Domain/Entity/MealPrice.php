<?php
declare(strict_types=1);

namespace App\Domain\Entity;

/**
 * 食事の単価（m_meal_price_info）。年（暦年。1〜12月）ごとに1行。控除表の金額に使う。
 */
final class MealPrice
{
    public function __construct(
        public readonly ?int $id,
        public readonly ?int $year,
        public readonly ?int $morning,
        public readonly ?int $lunch,
        public readonly ?int $dinner,
        public readonly ?int $bento,
    ) {}

    /**
     * 食数から控除額を出す（単価が空の食事は 0 円）。
     *
     * @param array{morning: int, lunch: int, dinner: int, bento: int} $counts
     */
    public function totalFor(array $counts): int
    {
        return $counts['bento'] * ($this->bento ?? 0)
            + $counts['morning'] * ($this->morning ?? 0)
            + $counts['lunch'] * ($this->lunch ?? 0)
            + $counts['dinner'] * ($this->dinner ?? 0);
    }
}
