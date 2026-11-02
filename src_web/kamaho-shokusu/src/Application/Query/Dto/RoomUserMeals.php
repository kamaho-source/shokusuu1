<?php
declare(strict_types=1);

namespace App\Application\Query\Dto;

/**
 * 部屋の利用者1人と、その日にこの部屋で食べる食事。
 */
final class RoomUserMeals
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
     * API の応答の形（従来のキー）。
     *
     * @return array{id: int, name: string, morning: bool, noon: bool, night: bool, bento: bool}
     */
    public function toArray(): array
    {
        return [
            'id'      => $this->userId,
            'name'    => $this->name,
            'morning' => $this->breakfast,
            'noon'    => $this->lunch,
            'night'   => $this->dinner,
            'bento'   => $this->bento,
        ];
    }
}
