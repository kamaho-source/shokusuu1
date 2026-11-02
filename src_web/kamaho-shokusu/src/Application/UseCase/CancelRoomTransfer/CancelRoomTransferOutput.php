<?php
declare(strict_types=1);

namespace App\Application\UseCase\CancelRoomTransfer;

/**
 * 部屋異動の予約の取り消しの結果。
 */
final class CancelRoomTransferOutput
{
    /** 取り消した */
    public const CANCELLED = 'cancelled';
    /** 取り消せない（予約中でない）。$message に理由 */
    public const REJECTED = 'rejected';

    private function __construct(
        public readonly string $status,
        public readonly ?string $message = null,
    ) {}

    public static function cancelled(): self
    {
        return new self(self::CANCELLED);
    }

    public static function rejected(string $message): self
    {
        return new self(self::REJECTED, $message);
    }
}
