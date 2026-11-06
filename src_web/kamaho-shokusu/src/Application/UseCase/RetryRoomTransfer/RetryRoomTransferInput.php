<?php
declare(strict_types=1);

namespace App\Application\UseCase\RetryRoomTransfer;

/**
 * 適用失敗の部屋異動をやり直すときの入力。
 */
final class RetryRoomTransferInput
{
    /**
     * @param string $actorName やり直した人の名前（更新者として残す）
     */
    public function __construct(
        public readonly int $scheduleId,
        public readonly string $actorName,
    ) {}
}
