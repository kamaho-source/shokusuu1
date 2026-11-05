<?php
declare(strict_types=1);

namespace App\Application\UseCase\SaveActualMeal;

/**
 * 実食の保存の結果。status と画面に出す文言、保存後の版数（保存できたときだけ）。
 */
final class SaveActualMealOutput
{
    /** 保存した */
    public const SAVED = 'saved';
    /** 部屋が使えない・対象者が部屋に所属していない（HTTP 403） */
    public const FORBIDDEN = 'forbidden';
    /** 食事区分が不正・承認済み・版数の競合・保存の失敗（HTTP 409） */
    public const REJECTED = 'rejected';

    private function __construct(
        public readonly string $status,
        public readonly string $message,
        public readonly ?int $version = null,
    ) {}

    public static function saved(int $version): self
    {
        return new self(self::SAVED, '保存しました。', $version);
    }

    public static function forbidden(string $message): self
    {
        return new self(self::FORBIDDEN, $message);
    }

    public static function rejected(string $message): self
    {
        return new self(self::REJECTED, $message);
    }
}
