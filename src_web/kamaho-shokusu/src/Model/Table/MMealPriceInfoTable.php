<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * MMealPriceInfo Model
 *
 * @method \App\Model\Entity\MMealPriceInfo newEmptyEntity()
 * @method \App\Model\Entity\MMealPriceInfo newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\MMealPriceInfo> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\MMealPriceInfo get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\MMealPriceInfo findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\MMealPriceInfo patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\MMealPriceInfo> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\MMealPriceInfo|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\MMealPriceInfo saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\MMealPriceInfo>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\MMealPriceInfo>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\MMealPriceInfo>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\MMealPriceInfo> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\MMealPriceInfo>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\MMealPriceInfo>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\MMealPriceInfo>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\MMealPriceInfo> deleteManyOrFail(iterable $entities, array $options = [])
 */
class MMealPriceInfoTable extends Table
{
    /**
     * Initialize method
     *
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('m_meal_price_info');
        $this->setDisplayField('i_id');
        $this->setPrimaryKey('i_id');
    }

    /**
     * Default validation rules.
     *
     * @param \Cake\Validation\Validator $validator Validator instance.
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('i_fiscal_year')
            ->allowEmptyString('i_fiscal_year');

        $validator
            ->integer('i_morning_price')
            ->allowEmptyString('i_morning_price');

        $validator
            ->integer('i_lunch_price')
            ->allowEmptyString('i_lunch_price');

        $validator
            ->integer('i_dinner_price')
            ->allowEmptyString('i_dinner_price');

        $validator
            ->integer('i_bento_price')
            ->allowEmptyString('i_bento_price');

        $validator
            ->dateTime('dt_create')
            ->allowEmptyDateTime('dt_create');

        $validator
            ->scalar('c_create_user')
            ->maxLength('c_create_user', 50)
            ->allowEmptyString('c_create_user');

        $validator
            ->dateTime('dt_update')
            ->allowEmptyDateTime('dt_update');

        $validator
            ->scalar('c_update_user')
            ->maxLength('c_update_user', 50)
            ->allowEmptyString('c_update_user');

        return $validator;
    }
}
