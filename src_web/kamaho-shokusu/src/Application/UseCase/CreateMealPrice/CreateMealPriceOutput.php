<?php
declare(strict_types=1);

namespace App\Application\UseCase\CreateMealPrice;

/**
 * 単価の登録の結果。
 */
final class CreateMealPriceOutput
{
    /**
     * @param int $id 登録した単価の ID
     */
    public function __construct(
        public readonly int $id,
    ) {}
}
