<?php
declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Entity\MealPrice;
use App\Domain\Repository\MealPriceRepositoryInterface;
use Cake\Datasource\EntityInterface;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\I18n\DateTime;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;

/**
 * MealPriceRepositoryInterface の CakePHP 実装（m_meal_price_info）。
 */
final class CakeMealPriceRepository implements MealPriceRepositoryInterface
{
    private static function table(): Table
    {
        return TableRegistry::getTableLocator()->get('MMealPriceInfo');
    }

    /** 行を単価にする（CakeMealPriceQuery と共通）。 */
    public static function toPrice(EntityInterface $row): MealPrice
    {
        $int = static fn(string $f): ?int => $row->get($f) === null ? null : (int)$row->get($f);

        return new MealPrice((int)$row->get('i_id'), $int('i_fiscal_year'), $int('i_morning_price'), $int('i_lunch_price'), $int('i_dinner_price'), $int('i_bento_price'));
    }

    public function find(int $id): ?MealPrice
    {
        try {
            return self::toPrice(self::table()->get($id));
        } catch (RecordNotFoundException) {
            return null;
        }
    }

    public function findByYear(int $year): ?MealPrice
    {
        $row = self::table()->find()->where(['i_fiscal_year' => $year])->orderByAsc('i_id')->first();

        return $row === null ? null : self::toPrice($row);
    }

    public function yearExists(int $year, ?int $exceptId = null): bool
    {
        $where = ['i_fiscal_year' => $year];
        if ($exceptId !== null) {
            $where['i_id !='] = $exceptId;
        }

        return self::table()->exists($where);
    }

    /**
     * @return array<string, int|null>
     */
    private static function fields(MealPrice $price): array
    {
        return [
            'i_fiscal_year'   => $price->year,
            'i_morning_price' => $price->morning,
            'i_lunch_price'   => $price->lunch,
            'i_dinner_price'  => $price->dinner,
            'i_bento_price'   => $price->bento,
        ];
    }

    public function add(MealPrice $price, ?string $actorName): ?int
    {
        $table = self::table();
        $entity = $table->patchEntity($table->newEmptyEntity(), self::fields($price));
        $entity->set('dt_create', DateTime::now()->format('Y-m-d H:i:s'));
        $entity->set('c_create_user', $actorName);

        return $table->save($entity) ? (int)$entity->get('i_id') : null;
    }

    public function update(MealPrice $price, ?string $actorName): bool
    {
        $table = self::table();
        $entity = $table->patchEntity($table->get((int)$price->id), self::fields($price));
        $entity->set('dt_update', DateTime::now()->format('Y-m-d H:i:s'));
        $entity->set('c_update_user', $actorName);

        return (bool)$table->save($entity);
    }

    public function delete(int $id): bool
    {
        $table = self::table();

        return $table->delete($table->get($id));
    }
}
