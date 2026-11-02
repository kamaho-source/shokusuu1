<?php
declare(strict_types=1);

namespace App\Application\Query\Dto;

/**
 * エクセル食数予約の表（部屋ごとの予約と、日ごとの合計）。
 */
final class MealCountGrid
{
    /**
     * @param list<string> $dates YYYY-MM-DD
     * @param array<int, string> $meals 食事 => 名前（朝・昼・夕・弁）
     * @param array<int, MealGridRoom> $rooms 部屋ID => 部屋
     * @param array<string, array<int, int>> $dailyTotals 日付 => 食事 => 表に出ている部屋で食べる人数
     */
    public function __construct(
        public readonly array $dates,
        public readonly array $meals,
        public readonly array $rooms,
        public readonly array $dailyTotals,
    ) {}

    /**
     * 期間全体の食事ごとの合計。
     *
     * @return array<int, int>
     */
    public function totalsByMeal(): array
    {
        $totals = array_fill_keys(array_keys($this->meals), 0);
        foreach ($this->dailyTotals as $dateTotals) {
            foreach ($dateTotals as $mealType => $count) {
                $totals[$mealType] += $count;
            }
        }

        return $totals;
    }
}
