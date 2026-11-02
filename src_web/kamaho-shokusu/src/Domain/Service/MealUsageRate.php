<?php
declare(strict_types=1);

namespace App\Domain\Service;

/**
 * システムレポートの使用率。予約数 ÷（利用者数 × 日数 × 食事の数 4）× 100、小数第1位で丸める。利用者がいなければ 0。
 */
final class MealUsageRate
{
    /** 朝・昼・夕・弁当 */
    public const MEAL_TYPES = 4;

    public static function percent(int $reservations, int $users, int $days): float
    {
        $capacity = $users * $days * self::MEAL_TYPES;

        return $capacity > 0 ? round($reservations / $capacity * 100, 1) : 0.0;
    }

    private function __construct() {}
}
