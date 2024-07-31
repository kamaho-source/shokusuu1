<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * MUserInfo Model
 *
 * @method \App\Model\Entity\MUserInfo newEmptyEntity()
 * @method \App\Model\Entity\MUserInfo newEntity(array $data, array $options = [])
 * @method array<\App\Model\Entity\MUserInfo> newEntities(array $data, array $options = [])
 * @method \App\Model\Entity\MUserInfo get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \App\Model\Entity\MUserInfo findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \App\Model\Entity\MUserInfo patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\App\Model\Entity\MUserInfo> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \App\Model\Entity\MUserInfo|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \App\Model\Entity\MUserInfo saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\App\Model\Entity\MUserInfo>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\MUserInfo>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\MUserInfo>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\MUserInfo> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\MUserInfo>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\MUserInfo>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\App\Model\Entity\MUserInfo>|\Cake\Datasource\ResultSetInterface<\App\Model\Entity\MUserInfo> deleteManyOrFail(iterable $entities, array $options = [])
 */
class MUserInfoTable extends Table
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

        $this->setTable('m_user_info');
        $this->setDisplayField('i_id_user');
        $this->setPrimaryKey('i_id_user');
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
            ->scalar('c_login_account')
            ->maxLength('c_login_account', 50)
            ->allowEmptyString('c_login_account');

        $validator
            ->scalar('c_login_passwd')
            ->maxLength('c_login_passwd', 255)
            ->allowEmptyString('c_login_passwd');

        $validator
            ->scalar('c__user_name')
            ->maxLength('c__user_name', 50)
            ->allowEmptyString('c__user_name');

        $validator
            ->allowEmptyString('i_admin');

        $validator
            ->integer('i_disp__no')
            ->allowEmptyString('i_disp__no');

        $validator
            ->allowEmptyString('i_enable');

        $validator
            ->allowEmptyString('i_del_flag');

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
