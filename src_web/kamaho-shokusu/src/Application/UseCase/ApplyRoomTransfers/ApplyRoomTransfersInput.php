<?php
declare(strict_types=1);

namespace App\Application\UseCase\ApplyRoomTransfers;

/**
 * 部屋異動の適用の入力。
 */
final class ApplyRoomTransfersInput
{
    /**
     * @param string $date 基準日（'Y-m-d'）。有効開始日がこの日以前の予約を適用する
     * @param bool $dryRun true なら適用せずに取り消す（件数だけ数える）
     */
    public function __construct(
        public readonly string $date,
        public readonly bool $dryRun = false,
    ) {}
}
