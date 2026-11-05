<?php
declare(strict_types=1);

namespace App\Application\UseCase\ReflectApprovedToReservation;

/**
 * 集計への反映の結果。
 */
final class ReflectApprovedToReservationOutput
{
    /**
     * @param int $groupCount 書いた集計の行数（部屋 × 日付 × 食事区分）
     * @param int $recordCount 数えた予約の件数
     */
    public function __construct(
        public readonly int $groupCount,
        public readonly int $recordCount,
    ) {}
}
