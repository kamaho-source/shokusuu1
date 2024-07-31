<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * MRoomInfo Model
 *
 * @method \App\Model\Entity\MRoomInfo newEmptyEntity()
 * @method \App\Model\Entity\MRoomInfo newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\MRoomInfo> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\MRoomInfo get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\MRoomInfo findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\MRoomInfo patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\MRoomInfo> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\MRoomInfo|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\MRoomInfo saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\MRoomInfo>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\MRoomInfo>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\MRoomInfo>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\MRoomInfo> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\MRoomInfo>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\MRoomInfo>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\MRoomInfo>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\MRoomInfo> deleteManyOrFail(iterable $entities, array $options = [])
 */
class MRoomInfoTable extends Table
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

        $this->setTable('m_room_info');
        $this->setDisplayField('i_id_room');
        $this->setPrimaryKey('i_id_room');
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
            ->scalar('c_room_name')
            ->maxLength('c_room_name', 50)
            ->allowEmptyString('c_room_name');

        $validator
            ->integer('i_disp_no')
            ->allowEmptyString('i_disp_no');

        $validator
            ->allowEmptyString('i_enable');

        $validator
            ->allowEmptyString('i_del_flg');

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
