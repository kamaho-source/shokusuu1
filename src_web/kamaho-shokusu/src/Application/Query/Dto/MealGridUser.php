<?php
declare(strict_types=1);

namespace App\Application\Query\Dto;

/**
 * エクセル食数予約の行に出す利用者1人。
 */
final class MealGridUser
{
    public function __construct(
        public readonly int $id,
        public readonly string $name,
        public readonly int $level,
    ) {}
}
