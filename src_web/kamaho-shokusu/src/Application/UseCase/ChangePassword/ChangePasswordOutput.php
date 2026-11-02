<?php
declare(strict_types=1);

namespace App\Application\UseCase\ChangePassword;

/**
 * パスワードの変更の結果。
 */
final class ChangePasswordOutput
{
    /** 変えた */
    public const CHANGED = 'changed';
    /** 入力の誤り（相手を選んでいない・一致しない・短い）。$message に理由。何も保存していない */
    public const REJECTED = 'rejected';
    /** 保存できなかった */
    public const SAVE_FAILED = 'save_failed';

    private function __construct(
        public readonly string $status,
        public readonly ?string $message = null,
    ) {}

    public static function changed(): self
    {
        return new self(self::CHANGED);
    }

    public static function rejected(string $message): self
    {
        return new self(self::REJECTED, $message);
    }

    public static function saveFailed(): self
    {
        return new self(self::SAVE_FAILED);
    }
}
