<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * TReservationInfo Model
 *
 * @method \App\Model\Entity\TReservationInfo newEmptyEntity()
 * @method \App\Model\Entity\TReservationInfo newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\TReservationInfo> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\TReservationInfo get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\TReservationInfo findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\TReservationInfo patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\TReservationInfo> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\TReservationInfo|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\TReservationInfo saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\TReservationInfo>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TReservationInfo>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\TReservationInfo>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TReservationInfo> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\TReservationInfo>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TReservationInfo>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\TReservationInfo>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TReservationInfo> deleteManyOrFail(iterable $entities, array $options = [])
 */
class TReservationInfoTable extends Table
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

        $this->setTable('t_reservation_info');
        $this->setDisplayField(['d_reservation_date', 'i_id_room', 'c_reservation_type']);
        $this->setPrimaryKey(['d_reservation_date', 'i_id_room', 'c_reservation_type']);

        $this->belongsTo('MRoomInfo', [
            'foreignKey' => 'i_id_room', // 実際の外部キーのカラム名
            'joinType' => 'INNER', // 必要に応じて 'LEFT' に変更
        ]);
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
            ->integer('i_taberu_ninzuu')
            ->allowEmptyString('i_taberu_ninzuu');

        $validator
            ->integer('i_tabenai_ninzuu')
            ->allowEmptyString('i_tabenai_ninzuu');

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

    public function getTotalMealsByDate()
    {
        $query = $this->find()
            ->select([
                'd_reservation_date',
                'total_meals' => $query->func()->sum('i_taberu_ninzuu')
            ])
            ->groupBy('d_reservation_date');
        return $query;
    }
}
