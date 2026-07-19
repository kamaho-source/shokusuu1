<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class TenantsTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('tenants');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp', [
            'events' => [
                'Model.beforeSave' => [
                    'created_at' => 'new',
                    'updated_at' => 'always',
                ],
            ],
        ]);

        $this->hasMany('Facilities', [
            'foreignKey' => 'tenant_id',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('tenant_code')
            ->maxLength('tenant_code', 50)
            ->requirePresence('tenant_code', 'create')
            ->notEmptyString('tenant_code');

        $validator
            ->scalar('name')
            ->maxLength('name', 100)
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

        $validator
            ->scalar('status')
            ->inList('status', ['trial', 'active', 'suspended', 'terminated'])
            ->requirePresence('status', 'create')
            ->notEmptyString('status');

        $validator
            ->scalar('plan_code')
            ->maxLength('plan_code', 50)
            ->allowEmptyString('plan_code');

        $validator
            ->dateTime('contract_started_at')
            ->allowEmptyDateTime('contract_started_at');

        $validator
            ->dateTime('contract_ended_at')
            ->allowEmptyDateTime('contract_ended_at');

        return $validator;
    }
}
