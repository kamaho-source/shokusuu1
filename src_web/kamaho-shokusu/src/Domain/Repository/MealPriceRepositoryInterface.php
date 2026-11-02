<?php
declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\MealPrice;

/**
 * 単価マスタの保存先。
 */
interface MealPriceRepositoryInterface
{
    public function find(int $id): ?MealPrice;

    /** その年の単価（同じ年が複数あれば ID の小さいもの）。無ければ null。 */
    public function findByYear(int $year): ?MealPrice;

    /** その年の単価が、指定の ID 以外にあるか。 */
    public function yearExists(int $year, ?int $exceptId = null): bool;

    /**
     * 新しく登録する。保存できなければ null。
     *
     * @return int|null 登録した単価の ID
     */
    public function add(MealPrice $price, ?string $actorName): ?int;

    /** 更新する。保存できなければ false。 */
    public function update(MealPrice $price, ?string $actorName): bool;

    /** 削除する。消せなければ false。 */
    public function delete(int $id): bool;
}
