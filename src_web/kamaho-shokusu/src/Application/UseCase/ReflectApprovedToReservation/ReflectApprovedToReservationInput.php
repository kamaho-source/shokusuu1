<?php
declare(strict_types=1);

namespace App\Application\UseCase\ReflectApprovedToReservation;

/**
 * 最終承認済みの予約を集計へ反映するときの Input DTO。null は絞らない。
 */
final class ReflectApprovedToReservationInput
{
    public function __construct(
        public readonly ?int $roomId,
        public readonly ?string $dateFrom,
        public readonly ?string $dateTo,
        public readonly string $actorName,
    ) {}
}
