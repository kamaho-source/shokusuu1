<?php
declare(strict_types=1);

namespace App\Application\UseCase\CopyReservations;

/**
 * 予約コピーのプレビュー（件数のみ）。
 *
 * 週は従来どおり invalid_date を出さない：今日より前の日は willSkip に数え（fromCounts）、応答にキーを含めない（toArray）。
 * この扱いはこのクラスの中だけで行う。
 */
final class ReservationCopyPreview
{
    private function __construct(
        public readonly string $mode,
        public readonly int $total,
        public readonly int $willCopy,
        public readonly int $willSkip,
        public readonly int $invalidDate,
    ) {}

    /**
     * 判定ごとの件数から作る。週は無効な日付をスキップに含め、invalidDate を 0 にする。
     */
    public static function fromCounts(string $mode, int $total, int $copy, int $skip, int $invalid): self
    {
        if ($mode === CopyReservationsInput::WEEK) {
            return new self($mode, $total, $copy, $skip + $invalid, 0);
        }

        return new self($mode, $total, $copy, $skip, $invalid);
    }

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
