<?php
declare(strict_types=1);

namespace App\Application\UseCase\RetryRoomTransfer;

/**
 * 適用失敗の部屋異動をやり直した結果。
 */
final class RetryRoomTransferOutput
{
    /** 予約中に戻した（次のバッチで適用する） */
    public const RETRIED = 'retried';
    /** やり直せない（適用失敗でない）。$message に理由 */
    public const REJECTED = 'rejected';

    private function __construct(
        public readonly string $status,
        public readonly ?string $message = null,
    ) {}

    public static function retried(): self
    {
        return new self(self::RETRIED);
    }

    public static function rejected(string $message): self
    {
        return new self(self::REJECTED, $message);
    }
}
