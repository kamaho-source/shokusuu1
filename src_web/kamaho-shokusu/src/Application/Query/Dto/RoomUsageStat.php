<?php
declare(strict_types=1);

namespace App\Application\Query\Dto;

/**
 * システムレポートの部屋1つ分（子ども・大人の利用者数・予約数・使用率）。
 */
final class RoomUsageStat
{
    public function __construct(
        public readonly int $roomId,
        public readonly string $roomName,
        public readonly int $childUsers,
        public readonly int $adultUsers,
        public readonly int $childReservations,
        public readonly int $adultReservations,
        public readonly float $childUsageRate,
        public readonly float $adultUsageRate,
    ) {}

    /**
     * @return array{room_id: int, room_name: string, child_users: int, adult_users: int, child_reservations: int, adult_reservations: int, child_usage_rate: float, adult_usage_rate: float} API の応答の形
     */
    public function toArray(): array
    {
        return [
            'room_id'            => $this->roomId,
            'room_name'          => $this->roomName,
            'child_users'        => $this->childUsers,
            'adult_users'        => $this->adultUsers,
            'child_reservations' => $this->childReservations,
            'adult_reservations' => $this->adultReservations,
            'child_usage_rate'   => $this->childUsageRate,
            'adult_usage_rate'   => $this->adultUsageRate,
        ];
    }
}
