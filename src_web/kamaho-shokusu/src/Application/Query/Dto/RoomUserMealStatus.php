<?php
declare(strict_types=1);

namespace App\Application\Query\Dto;

/**
 * 直前編集の画面に出す利用者1人と、その日この部屋で食べる食事。
 */
final class RoomUserMealStatus
{
    public function __construct(
        public readonly int $userId,
        public readonly string $name,
        public readonly bool $breakfast,
        public readonly bool $lunch,
        public readonly bool $dinner,
        public readonly bool $bento,
    ) {}

    /**
     * @return array{id: int, name: string, meals: array{morning: bool, noon: bool, night: bool, bento: bool}} API の応答の形
     */
    public function toArray(): array
    {
        return [
            'id'    => $this->userId,
            'name'  => $this->name,
            'meals' => ['morning' => $this->breakfast, 'noon' => $this->lunch, 'night' => $this->dinner, 'bento' => $this->bento],
        ];
    }
}
