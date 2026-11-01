<?php
declare(strict_types=1);

namespace App\Application\UseCase\ChangeUserRole;

/**
 * 権限の変更の結果。
 */
final class ChangeUserRoleOutput
{
    /** 変えた */
    public const CHANGED = 'changed';
    /** 別の権限との競合（$message に理由） */
    public const CONFLICT = 'conflict';
    /** その画面からは付けられない値 */
    public const NOT_ASSIGNABLE = 'not_assignable';
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

    public static function conflict(string $message): self
    {
        return new self(self::CONFLICT, $message);
    }

    public static function notAssignable(): self
    {
        return new self(self::NOT_ASSIGNABLE);
    }

    public static function saveFailed(): self
    {
        return new self(self::SAVE_FAILED);
    }
}
