<?php
declare(strict_types=1);

namespace App\Application\UseCase\CreateUser;

/**
 * 利用者の新規登録の結果。
 */
final class CreateUserOutput
{
    /** 登録した */
    public const CREATED = 'created';
    /** 入力の誤り（権限・プロフィール・登録先の部屋・ログインIDの重複）。$message に理由 */
    public const REJECTED = 'rejected';
    /** テーブルの入力チェックに当たった。$fieldErrors に項目ごとの理由 */
    public const INVALID_FIELDS = 'invalid_fields';
    /** 保存できなかった */
    public const SAVE_FAILED = 'save_failed';

    /**
     * @param array<string, array<string, string>> $fieldErrors
     */
    private function __construct(
        public readonly string $status,
        public readonly ?string $message = null,
        public readonly array $fieldErrors = [],
    ) {}

    public static function created(): self
    {
        return new self(self::CREATED);
    }

    public static function rejected(string $message): self
    {
        return new self(self::REJECTED, $message);
    }

    /**
     * @param array<string, array<string, string>> $fieldErrors
     */
    public static function invalidFields(array $fieldErrors): self
    {
        return new self(self::INVALID_FIELDS, null, $fieldErrors);
    }

    public static function saveFailed(): self
    {
        return new self(self::SAVE_FAILED);
    }
}
