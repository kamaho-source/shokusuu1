<?php
declare(strict_types=1);

namespace App\Application\UseCase\BulkToggleMeals;

/**
 * 予約トグルの一括処理の結果（item ごと）。
 */
final class BulkToggleMealsOutput
{
    /**
     * @param array<int, array{ok:bool,value?:bool,details?:array<mixed>,message?:string}> $results item と同じ順序の結果
     */
    public function __construct(
        private readonly array $results,
    ) {}

    /**
     * 応答の JSON にそのまま入る形。
     *
     * @return array<int, array{ok:bool,value?:bool,details?:array<mixed>,message?:string}>
     */
    public function toArray(): array
    {
        return $this->results;
    }
}
