<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class FacilitiesTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('facilities');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp', [
            'events' => [
                'Model.beforeSave' => [
                    'created_at' => 'new',
                    'updated_at' => 'always',
                ],
            ],
        ]);

        $this->belongsTo('Tenants', [
            'foreignKey' => 'tenant_id',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('tenant_id')
            ->requirePresence('tenant_id', 'create')
            ->notEmptyString('tenant_id');

        $validator
            ->scalar('facility_code')
            ->maxLength('facility_code', 50)
            ->requirePresence('facility_code', 'create')
            ->notEmptyString('facility_code');

        $validator
            ->scalar('name')
            ->maxLength('name', 100)
            ->requirePresence('name', 'create')
            ->notEmptyString('name');

        $validator
            ->scalar('timezone')
            ->maxLength('timezone', 50)
            ->allowEmptyString('timezone');

        $validator
            ->boolean('is_active')
            ->allowEmptyString('is_active');

        return $validator;
    }
}
