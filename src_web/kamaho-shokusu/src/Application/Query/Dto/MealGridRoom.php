<?php
declare(strict_types=1);

namespace App\Application\Query\Dto;

/**
 * エクセル食数予約の部屋1つ分（利用者ごと・日付ごと・食事ごとの予約）。
 */
final class MealGridRoom
{
    /**
     * @param list<MealGridUser> $users 表示順
     * @param array<int, array<string, array<int, bool>>> $grid 利用者ID => 日付 => 食事 => この部屋で食べるか
     * @param array<int, array<string, array<int, int>>> $otherRoom 利用者ID => 日付 => 食事 => 予約済みの他の部屋（この部屋では選べない）
     */
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly array $users,
        public readonly array $grid,
        public readonly array $otherRoom,
    ) {}
}
