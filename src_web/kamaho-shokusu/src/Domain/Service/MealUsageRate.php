<?php
declare(strict_types=1);

namespace App\Domain\Service;

/**
 * 使用率（システムレポート・部屋使用率）。食べる件数 ÷（利用者数 × 日数 × 食事の数）× 100、小数第1位で丸める。分母が 0 なら 0。
 * 食事の数は、全食事なら 4、1つの食事に絞るなら 1。
 */
final class MealUsageRate
{
    /** 朝・昼・夕・弁当 */
    public const MEAL_TYPES = 4;

    public static function percent(int $reservations, int $users, int $days, int $mealTypes = self::MEAL_TYPES): float
    {
        $capacity = self::capacity($users, $days, $mealTypes);

        return $capacity > 0 ? round($reservations / $capacity * 100, 1) : 0.0;
    }

    /** 分母（利用者数 × 日数 × 食事の数）。 */
    public static function capacity(int $users, int $days, int $mealTypes = self::MEAL_TYPES): int
    {
        return $users * $days * $mealTypes;
    }

    private function __construct() {}
}
