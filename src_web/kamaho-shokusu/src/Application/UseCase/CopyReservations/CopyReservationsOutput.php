<?php
declare(strict_types=1);

namespace App\Application\UseCase\CopyReservations;

/**
 * 予約コピーの結果（件数）。
 */
final class CopyReservationsOutput
{
    /**
     * @param int $total コピー元の行数
     * @param int $copied 写した行数
     * @param int $skipped スキップした行数
     * @param int $invalidDate コピー先の日が無い・今日より前の行数
     */
    public function __construct(
        public readonly string $mode,
        public readonly int $total,
        public readonly int $copied,
        public readonly int $skipped,
        public readonly int $invalidDate,
    ) {}

    /**
     * 応答の data にそのまま入れる形。
     *
     * @return array{mode: string, total: int, copied: int, skipped: int, invalid_date: int, affected: int}
     */
    public function toArray(): array
    {
        return [
            'mode'         => $this->mode,
            'total'        => $this->total,
            'copied'       => $this->copied,
            'skipped'      => $this->skipped,
            'invalid_date' => $this->invalidDate,
            'affected'     => $this->copied,
        ];
    }
}
