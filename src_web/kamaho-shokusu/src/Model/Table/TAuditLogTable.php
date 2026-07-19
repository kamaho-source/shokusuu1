<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * TAuditLog Model
 */
class TAuditLogTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('t_audit_log');
        $this->setDisplayField('c_action');
        $this->setPrimaryKey('i_id_audit');

        $this->belongsTo('MUserInfo', [
            'foreignKey' => 'i_actor_user_id',
            'joinType'   => 'LEFT',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->notEmptyString('c_category')
            ->notEmptyString('c_action')
            ->notEmptyString('c_actor_user_name')
            ->notEmptyString('dt_create');

        return $validator;
    }
}
