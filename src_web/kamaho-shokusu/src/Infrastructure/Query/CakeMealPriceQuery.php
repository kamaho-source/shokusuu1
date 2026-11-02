<?php
declare(strict_types=1);

namespace App\Infrastructure\Query;

use App\Application\Query\MealPriceQueryInterface;
use App\Domain\Entity\MealPrice;
use App\Infrastructure\Repository\CakeMealPriceRepository;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;

/**
 * MealPriceQueryInterface の CakePHP 実装（m_meal_price_info）。
 */
final class CakeMealPriceQuery implements MealPriceQueryInterface
{
    private static function table(): Table
    {
        return TableRegistry::getTableLocator()->get('MMealPriceInfo');
    }

    public function find(int $id): ?MealPrice
    {
        return (new CakeMealPriceRepository())->find($id);
    }

    public function all(): array
    {
        return array_map(CakeMealPriceRepository::toPrice(...), self::table()->find()->orderByAsc('i_id')->all()->toList());
    }

    public function years(): array
    {
        $years = [];
        foreach (self::table()->find()->select(['i_fiscal_year'])->distinct(['i_fiscal_year'])->orderBy(['i_fiscal_year'])->all() as $row) {
            if ($row->i_fiscal_year !== null) {
                $years[] = (int)$row->i_fiscal_year;
            }
        }

        return $years;
    }
}
