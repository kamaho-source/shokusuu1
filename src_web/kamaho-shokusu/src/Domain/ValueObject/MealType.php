<?php
declare(strict_types=1);

namespace App\Domain\ValueObject;

/**
 * 食事区分（i_reservation_type）と、食事どうしの組み合わせルール。
 *
 * 1〜4 以外の値はこの型にならない（from() は ValueError、tryFrom() は null）。
 * 昼食と弁当は同じ日に同時に予約できない（どちらか一方だけ）。
 */
enum MealType: int
{
    case Breakfast = 1;
    case Lunch     = 2;
    case Dinner    = 3;
    case Bento     = 4;

    /** 同じ日に同時に予約できない組（昼食・弁当）の区分値。 */
    public const EXCLUSIVE_VALUES = [self::Lunch->value, self::Bento->value];

    /** 同時に予約できない相手（昼食なら弁当、弁当なら昼食）。相手がいなければ null。 */
    public function exclusiveCounterpart(): ?self
    {
        return match ($this) {
            self::Lunch => self::Bento,
            self::Bento => self::Lunch,
            default     => null,
        };
    }

    /** 区分名（朝食・昼食・夕食・弁当）。 */
    public function label(): string
    {
        return match ($this) {
            self::Breakfast => '朝食',
            self::Lunch     => '昼食',
            self::Dinner    => '夕食',
            self::Bento     => '弁当',
        };
    }

    /**
     * DB の区分値から、同時に予約できない相手の区分値を返す。相手がいない・不正な値なら null。
     */
    public static function counterpartValue(int $value): ?int
    {
        return self::tryFrom($value)?->exclusiveCounterpart()?->value;
    }

    /** DB の区分値が昼食・弁当のどちらかか。 */
    public static function isExclusiveValue(int $value): bool
    {
        return self::counterpartValue($value) !== null;
    }

    /** DB の区分値の名前。不正な値なら空文字。 */
    public static function labelOf(int $value): string
    {
        return self::tryFrom($value)?->label() ?? '';
    }
}
