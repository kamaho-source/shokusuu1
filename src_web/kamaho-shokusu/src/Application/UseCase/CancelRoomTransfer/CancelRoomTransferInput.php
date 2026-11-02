<?php
declare(strict_types=1);

namespace App\Application\UseCase\CancelRoomTransfer;

/**
 * 部屋異動の予約の取り消しの入力。
 */
final class CancelRoomTransferInput
{
    /**
     * @param string $actorName 取り消した人の名前（更新者として残す）
     */
    public function __construct(
        public readonly int $scheduleId,
        public readonly string $actorName,
    ) {}
}
