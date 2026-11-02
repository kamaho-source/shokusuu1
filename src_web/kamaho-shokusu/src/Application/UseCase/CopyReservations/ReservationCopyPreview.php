<?php
declare(strict_types=1);

namespace App\Application\UseCase\CopyReservations;

/**
 * 予約コピーのプレビュー（件数のみ）。週は今日より前の日を willSkip に数え、invalidDate を返さない（従来どおり）。
 */
final class ReservationCopyPreview
{
    public function __construct(
        public readonly string $mode,
        public readonly int $total,
        public readonly int $willCopy,
        public readonly int $willSkip,
        public readonly int $invalidDate,
    ) {}

    /**
     * 応答の data.preview にそのまま入れる形。
     *
     * @return array{total: int, will_copy: int, will_skip: int, invalid_date?: int}
     */
    public function toArray(): array
    {
        $counts = ['total' => $this->total, 'will_copy' => $this->willCopy, 'will_skip' => $this->willSkip];
        if ($this->mode === CopyReservationsInput::WEEK) {
            return $counts;
        }

        return $counts + ['invalid_date' => $this->invalidDate];
    }
}
