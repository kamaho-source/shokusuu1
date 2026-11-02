<?php
declare(strict_types=1);

namespace App\Application\UseCase\DeleteMealPrice;

/**
 * 単価の削除の結果。
 */
final class DeleteMealPriceOutput
{
    /**
     * @param bool $deleted 消せたか
     */
    public function __construct(
        public readonly bool $deleted,
    ) {}
}
