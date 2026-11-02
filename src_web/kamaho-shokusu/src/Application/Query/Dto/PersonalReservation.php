<?php
declare(strict_types=1);

namespace App\Application\Query\Dto;

/**
 * 本人のその日の予約と、選べる部屋。
 */
final class PersonalReservation
{
    /**
     * @param array<int|string, array<int|string, true>> $reservation 部屋ID => 食事 => 食べる
     * @param array<int, string> $authorizedRooms 部屋ID => 部屋名
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $userName,
        public readonly array $reservation,
        public readonly array $authorizedRooms,
    ) {}

    /**
     * API の応答の形（従来のキー）。
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'user'             => ['i_id_user' => $this->userId, 'c_user_name' => $this->userName],
            'reservation'      => $this->reservation,
            'authorized_rooms' => $this->authorizedRooms,
        ];
    }
}
