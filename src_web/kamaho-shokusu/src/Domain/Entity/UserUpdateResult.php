<?php
declare(strict_types=1);

namespace App\Domain\Entity;

/**
 * 利用者のプロフィールを保存した結果。
 */
final class UserUpdateResult
{
    /**
     * @param array<string, int|string|null> $before 保存前のプロフィール（保存できたときだけ）
     * @param array<string, int|string|null> $after 保存後のプロフィール（保存できたときだけ）
     * @param array<string, array<string, string>> $fieldErrors 項目ごとの入力の誤り（項目 => [規則 => 文言]）
     */
    private function __construct(
        public readonly bool $saved,
        public readonly array $before = [],
        public readonly array $after = [],
        public readonly array $fieldErrors = [],
    ) {}

    /**
     * @param array<string, int|string|null> $before
     * @param array<string, int|string|null> $after
     */
    public static function saved(array $before, array $after): self
    {
        return new self(true, $before, $after);
    }

    /**
     * @param array<string, array<string, string>> $fieldErrors 無ければ空（DB の保存の失敗）
     */
    public static function failed(array $fieldErrors): self
    {
        return new self(false, fieldErrors: $fieldErrors);
    }
}
