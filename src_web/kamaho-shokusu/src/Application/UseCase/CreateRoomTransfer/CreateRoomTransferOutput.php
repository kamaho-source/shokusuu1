<?php
declare(strict_types=1);

namespace App\Application\UseCase\CreateRoomTransfer;

/**
 * 部屋異動の予約の登録の結果。
 */
final class CreateRoomTransferOutput
{
    /** 登録した */
    public const CREATED = 'created';
    /** 入力の誤り・保存時のバリデーションエラー。$message に理由 */
    public const REJECTED = 'rejected';

    private function __construct(
        public readonly string $status,
        public readonly ?string $message = null,
    ) {}

    public static function created(): self
    {
        return new self(self::CREATED);
    }

    public static function rejected(string $message): self
    {
        return new self(self::REJECTED, $message);
    }
}
