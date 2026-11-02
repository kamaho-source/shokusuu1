<?php
declare(strict_types=1);

namespace App\Application\Query;

use App\Domain\Entity\MealPrice;

/**
 * 単価マスタの読み取り。
 */
interface MealPriceQueryInterface
{
    public function find(int $id): ?MealPrice;

    /**
     * すべて（ID の順）。単価マスタの一覧。
     *
     * @return list<MealPrice>
     */
    public function all(): array;

    /**
     * 単価が登録されている年（古い順）。控除表の年の選択肢。
     *
     * @return list<int>
     */
    public function years(): array;
}
