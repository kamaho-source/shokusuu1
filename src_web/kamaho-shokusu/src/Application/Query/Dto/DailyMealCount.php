<?php
declare(strict_types=1);

namespace App\Application\Query\Dto;

/**
 * ある日の、食事ごとの食べる人数と合計。
 */
final class DailyMealCount
{
    public function __construct(
        public readonly string $date,
        public readonly int $breakfast,
        public readonly int $lunch,
        public readonly int $dinner,
        public readonly int $bento,
        public readonly int $total,
    ) {}

    /**
     * @return array{date: string, morning: int, lunch: int, dinner: int, bento: int, total: int} API の応答・キャッシュの形
     */
    public function toArray(): array
    {
        return ['date' => $this->date, 'morning' => $this->breakfast, 'lunch' => $this->lunch, 'dinner' => $this->dinner, 'bento' => $this->bento, 'total' => $this->total];
    }

    /**
     * @param array<string, int|string> $row
     */
    public static function fromArray(array $row): self
    {
        return new self((string)$row['date'], (int)$row['morning'], (int)$row['lunch'], (int)$row['dinner'], (int)$row['bento'], (int)$row['total']);
    }
}
