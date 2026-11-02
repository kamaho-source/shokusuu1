<?php
declare(strict_types=1);

namespace App\Application\UseCase\BulkAddReservations;

/**
 * 週次一括登録の結果。
 */
final class BulkAddReservationsOutput
{
    /**
     * @param array{ok: bool, message: string, redirect_url?: string, conflict_date?: string} $result
     */
    public function __construct(
        private readonly array $result,
    ) {}

    /**
     * Controller が応答を組み立てる元の形（従来の戻り値と同じ）。
     *
     * @return array{ok: bool, message: string, redirect_url?: string, conflict_date?: string}
     */
    public function toArray(): array
    {
        return $this->result;
    }
}
