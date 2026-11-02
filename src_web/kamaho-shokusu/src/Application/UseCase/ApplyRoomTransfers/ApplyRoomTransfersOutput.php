<?php
declare(strict_types=1);

namespace App\Application\UseCase\ApplyRoomTransfers;

/**
 * 部屋異動の適用の結果。
 */
final class ApplyRoomTransfersOutput
{
    /**
     * @param int $applied 適用した件数（ドライランでは適用する予定の件数）
     * @param list<string> $errors 適用できなかった予約（'スケジュールID=n: 理由'）
     */
    public function __construct(
        public readonly int $applied,
        public readonly array $errors,
    ) {}
}
