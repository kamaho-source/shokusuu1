<?php
declare(strict_types=1);

namespace App\Application\Query\Dto;

/**
 * 日別の食数状況の画面に出す利用者1人。
 */
final class DayViewUser
{
    /**
     * @param string $staffId 職員ID（無ければ空）
     */
    public function __construct(
        public readonly int $userId,
        public readonly string $name,
        public readonly string $staffId,
    ) {}
}
