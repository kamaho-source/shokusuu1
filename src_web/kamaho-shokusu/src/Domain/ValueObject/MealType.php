<?php
declare(strict_types=1);

namespace App\Domain\ValueObject;

/**
 * 食事区分（i_reservation_type）と、食事どうしの組み合わせルール。
 *
 * 昼食と弁当は同じ日に同時に予約できない（どちらか一方だけ）。
 */
final class MealType
{
    public const BREAKFAST = 1;
    public const LUNCH     = 2;
    public const DINNER    = 3;
    public const BENTO     = 4;

    /** 同じ日に同時に予約できない組（昼食・弁当）。 */
    public const EXCLUSIVE = [self::LUNCH, self::BENTO];

    private const NAMES = [
        self::BREAKFAST => '朝食',
        self::LUNCH     => '昼食',
        self::DINNER    => '夕食',
        self::BENTO     => '弁当',
    ];

    /** 同時に予約できない相手の区分（昼食なら弁当、弁当なら昼食）。相手がいなければ null。 */
    public static function exclusiveCounterpart(int $mealType): ?int
    {
        return match ($mealType) {
            self::LUNCH => self::BENTO,
            self::BENTO => self::LUNCH,
            default     => null,
        };
    }

    /** 昼食・弁当のどちらかか。 */
    public static function isExclusive(int $mealType): bool
    {
        return self::exclusiveCounterpart($mealType) !== null;
    }

    /** 区分名（朝食・昼食・夕食・弁当）。 */
    public static function name(int $mealType): string
    {
        return self::NAMES[$mealType] ?? '';
    }
}
