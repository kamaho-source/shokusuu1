<?php
declare(strict_types=1);

namespace App\Application\Query\Dto;

/**
 * 部屋使用率の、職員1人分の使用率。
 */
final class StaffUsage
{
    public function __construct(
        public readonly int $userId,
        public readonly string $userName,
        public readonly int $capacity,
        public readonly int $eatCount,
        public readonly float $usageRate,
    ) {}

    /**
     * @return array{user_id: int, user_name: string, capacity: int, eat_count: int, usage_rate: float} API・画面の形
     */
    public function toArray(): array
    {
        return [
            'user_id'    => $this->userId,
            'user_name'  => $this->userName,
            'capacity'   => $this->capacity,
            'eat_count'  => $this->eatCount,
            'usage_rate' => $this->usageRate,
        ];
    }
}
