<?php
declare(strict_types=1);

namespace App\Application\UseCase\ReportNoMeal;

/**
 * 今日の食事を「食べない」で報告する Input DTO（ログイン中の利用者本人）。
 */
final class ReportNoMealInput
{
    public function __construct(
        public readonly int $userId,
        public readonly string $userName,
    ) {}
}
