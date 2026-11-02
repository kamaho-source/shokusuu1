<?php
declare(strict_types=1);

namespace App\Application\Query\Dto;

/**
 * 年代・性別・日付ごとの食べる人数。
 */
final class RankMealCount
{
    public function __construct(
        public readonly string $rankName,
        public readonly string $gender,
        public readonly string $date,
        public readonly int $breakfast,
        public readonly int $lunch,
        public readonly int $dinner,
        public readonly int $bento,
        public readonly int $total,
    ) {}

    /**
     * @return array{rank_name: string, gender: string, reservation_date: string, breakfast: int, lunch: int, dinner: int, bento: int, total_eaters: int} API の応答・キャッシュの形
     */
    public function toArray(): array
    {
        return [
            'rank_name'        => $this->rankName,
            'gender'           => $this->gender,
            'reservation_date' => $this->date,
            'breakfast'        => $this->breakfast,
            'lunch'            => $this->lunch,
            'dinner'           => $this->dinner,
            'bento'            => $this->bento,
            'total_eaters'     => $this->total,
        ];
    }

    /**
     * @param array<string, int|string> $row
     */
    public static function fromArray(array $row): self
    {
        return new self((string)$row['rank_name'], (string)$row['gender'], (string)$row['reservation_date'], (int)$row['breakfast'], (int)$row['lunch'], (int)$row['dinner'], (int)$row['bento'], (int)$row['total_eaters']);
    }
}
