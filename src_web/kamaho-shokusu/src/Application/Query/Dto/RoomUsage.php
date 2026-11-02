<?php
declare(strict_types=1);

namespace App\Application\Query\Dto;

/**
 * 部屋使用率の、部屋1つ分。
 */
final class RoomUsage
{
    /**
     * @param list<StaffUsage> $staff 職員ごとの使用率（名前順）
     */
    public function __construct(
        public readonly int $roomId,
        public readonly string $roomName,
        public readonly int $userCount,
        public readonly int $capacity,
        public readonly int $eatCount,
        public readonly float $usageRate,
        public readonly array $staff,
    ) {}

    /** 使用率がしきい値（%）以下か（「使用率の低い部屋」に出す）。 */
    public function isLow(float $threshold): bool
    {
        return $this->usageRate <= $threshold;
    }

    /**
     * @return array{room_id: int, room_name: string, user_count: int, capacity: int, eat_count: int, usage_rate: float, staff: list<array<string, int|float|string>>} API・画面の形
     */
    public function toArray(): array
    {
        return [
            'room_id'    => $this->roomId,
            'room_name'  => $this->roomName,
            'user_count' => $this->userCount,
            'capacity'   => $this->capacity,
            'eat_count'  => $this->eatCount,
            'usage_rate' => $this->usageRate,
            'staff'      => array_map(static fn(StaffUsage $s) => $s->toArray(), $this->staff),
        ];
    }
}
