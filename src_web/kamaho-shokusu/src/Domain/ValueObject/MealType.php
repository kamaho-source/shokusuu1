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

    /** 表示名のセット。画面ごとに使い分けている表記を、文言を変えずにここへ集める。 */
    public const LABEL_FULL = 'full';               // 朝食・昼食・夕食・弁当
    public const LABEL_SHORT = 'short';             // 朝・昼・夕・弁当（承認・通知・部屋の利用状況）
    public const LABEL_SHORT_NIGHT = 'short_night'; // 朝・昼・夜・弁当（予約の詳細・レポート・予約履歴・実食）
    public const LABEL_TINY = 'tiny';               // 朝・昼・夕・弁（エクセル食数予約）
    public const LABEL_TINY_NIGHT = 'tiny_night';   // 朝・昼・夜・弁（予約カレンダー・承認ログ）

    /** @var array<string, array<int, string>> 表示名のセット（定数から参照できるよう公開する） */
    public const LABEL_SETS = [
        self::LABEL_FULL        => [1 => '朝食', 2 => '昼食', 3 => '夕食', 4 => '弁当'],
        self::LABEL_SHORT       => [1 => '朝', 2 => '昼', 3 => '夕', 4 => '弁当'],
        self::LABEL_SHORT_NIGHT => [1 => '朝', 2 => '昼', 3 => '夜', 4 => '弁当'],
        self::LABEL_TINY        => [1 => '朝', 2 => '昼', 3 => '夕', 4 => '弁'],
        self::LABEL_TINY_NIGHT  => [1 => '朝', 2 => '昼', 3 => '夜', 4 => '弁'],
    ];

    /** 同じ日に同時に予約できない組（昼食・弁当）の区分値。 */
    public const EXCLUSIVE_VALUES = [self::Lunch->value, self::Bento->value];

    /**
     * すべての区分値（1〜4）。
     *
     * @return list<int>
     */
    public static function values(): array
    {
        return array_map(static fn(self $t): int => $t->value, self::cases());
    }

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
        return self::LABEL_SETS[self::LABEL_FULL][$this->value];
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

    /**
     * その日に有効（食べる）な食事区分の中に、同時に予約できない組（昼食と弁当）が両方そろっているか。
     *
     * @param iterable<int> $effectiveValues その日に有効な食事区分の値（重複してよい）
     */
    public static function hasExclusiveConflict(iterable $effectiveValues): bool
    {
        $found = [];
        foreach ($effectiveValues as $value) {
            if (in_array($value, self::EXCLUSIVE_VALUES, true)) {
                $found[$value] = true;
            }
        }

        return count($found) === count(self::EXCLUSIVE_VALUES);
    }

    /**
     * 区分値 => 表示名。$withBento が false なら弁当を除く（実食の画面は朝・昼・夕だけ）。
     *
     * @param string $style LABEL_* のどれか
     * @return array<int, string>
     */
    public static function labels(string $style = self::LABEL_FULL, bool $withBento = true): array
    {
        $labels = self::LABEL_SETS[$style] ?? throw new \InvalidArgumentException('不明な表示名のセット: ' . $style);
        if (!$withBento) {
            unset($labels[self::Bento->value]);
        }

        return $labels;
    }

    /** DB の区分値の名前。不正な値なら空文字。 */
    public static function labelOf(int $value): string
    {
        return self::tryFrom($value)?->label() ?? '';
    }
}
