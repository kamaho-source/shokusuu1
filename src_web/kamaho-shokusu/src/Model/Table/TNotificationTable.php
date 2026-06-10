<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class TNotificationTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('t_notification');
        $this->setPrimaryKey('i_id_notification');
        $this->setDisplayField('c_title');

        $this->belongsTo('MUserInfo', [
            'foreignKey' => 'i_id_user',
            'joinType' => 'INNER',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('i_id_user')
            ->requirePresence('i_id_user', 'create')
            ->notEmptyString('i_id_user');

        $validator
            ->scalar('c_notification_type')
            ->maxLength('c_notification_type', 50)
            ->requirePresence('c_notification_type', 'create')
            ->notEmptyString('c_notification_type');

        $validator
            ->scalar('c_title')
            ->maxLength('c_title', 100)
            ->requirePresence('c_title', 'create')
            ->notEmptyString('c_title');

        $validator
            ->scalar('c_message')
            ->maxLength('c_message', 255)
            ->requirePresence('c_message', 'create')
            ->notEmptyString('c_message');

        $validator
            ->scalar('c_link')
            ->maxLength('c_link', 255)
            ->allowEmptyString('c_link');

        $validator
            ->integer('i_is_read')
            ->inList('i_is_read', [0, 1])
            ->allowEmptyString('i_is_read');

        $validator
            ->dateTime('dt_read')
            ->allowEmptyDateTime('dt_read');

        $validator
            ->dateTime('dt_create')
            ->requirePresence('dt_create', 'create')
            ->notEmptyDateTime('dt_create');

        return $validator;
    }
}
