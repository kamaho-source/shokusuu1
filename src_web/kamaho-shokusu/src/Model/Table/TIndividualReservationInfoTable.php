<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * TIndividualReservationInfo Model
 *
 * @method \App\Model\Entity\TIndividualReservationInfo newEmptyEntity()
 * @method \App\Model\Entity\TIndividualReservationInfo newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\TIndividualReservationInfo> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\TIndividualReservationInfo get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\TIndividualReservationInfo findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\TIndividualReservationInfo patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\TIndividualReservationInfo> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\TIndividualReservationInfo|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\TIndividualReservationInfo saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\TIndividualReservationInfo>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TIndividualReservationInfo>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\TIndividualReservationInfo>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TIndividualReservationInfo> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\TIndividualReservationInfo>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TIndividualReservationInfo>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\TIndividualReservationInfo>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\TIndividualReservationInfo> deleteManyOrFail(iterable $entities, array $options = [])
 */
class TIndividualReservationInfoTable extends Table
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

        $this->setTable('t_individual_reservation_info');
        $this->setDisplayField('i_id_user');
        $this->setPrimaryKey(['i_id_user', 'd_reservation_date', 'i_id_room']);
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
            ->integer('i_id_user')
            ->requirePresence('i_id_user', 'create')
            ->notEmptyString('i_id_user', 'User ID is required');

        $validator
            ->date('d_reservation_date')
            ->requirePresence('d_reservation_date', 'create')
            ->notEmptyDate('d_reservation_date', 'Reservation date is required');

        $validator
            ->integer('i_id_room')
            ->requirePresence('i_id_room', 'create')
            ->notEmptyString('i_id_room', 'Room ID is required');

        $validator
            ->integer('i_reservation_type')
            ->requirePresence('i_reservation_type', 'create')
            ->notEmptyString('i_reservation_type', 'Reservation type is required');

        $validator
            ->notEmptyString('eat_flag', 'Eat flag is required');

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
