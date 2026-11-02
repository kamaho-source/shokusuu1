<?php
declare(strict_types=1);

namespace App\Application\Query\Dto;

/**
 * 月次エクスポート（部屋・日付・食事ごとの食べる人数）。食事の名前（朝・昼・夜・弁当）をキーにする。
 */
final class MealExport
{
    /**
     * @param list<array<string, int|string>> $overall 1行 = ['部屋名' => …, '日付' => …, 食事名 => 人数, …]
     * @param array<string, array<string, array<string, int>>> $rooms 部屋名 => 日付 => 食事名 => 人数
     */
    public function __construct(
        public readonly array $overall,
        public readonly array $rooms,
    ) {}

    /**
     * @return array{overall: list<array<string, int|string>>, rooms: array<string, array<string, array<string, int>>>} API の応答・キャッシュの形
     */
    public function toArray(): array
    {
        return ['overall' => $this->overall, 'rooms' => $this->rooms];
    }

    /**
     * @param array{overall?: list<array<string, int|string>>, rooms?: array<string, array<string, array<string, int>>>} $row
     */
    public static function fromArray(array $row): self
    {
        return new self($row['overall'] ?? [], $row['rooms'] ?? []);
    }
}
