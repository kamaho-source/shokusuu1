<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class TApprovalLogTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('t_approval_log');
        $this->setDisplayField('i_id_approval');
        $this->setPrimaryKey('i_id_approval');

        $this->belongsTo('MUserInfo',    ['foreignKey' => 'i_id_user',    'joinType' => 'INNER']);
        $this->belongsTo('MRoomInfo',    ['foreignKey' => 'i_id_room',    'joinType' => 'INNER']);
        $this->belongsTo('Approvers',    ['className' => 'MUserInfo', 'foreignKey' => 'i_approver_id', 'joinType' => 'INNER']);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('i_id_user')->requirePresence('i_id_user', 'create')->notEmptyString('i_id_user')
            ->date('d_reservation_date')->requirePresence('d_reservation_date', 'create')->notEmptyDate('d_reservation_date')
            ->integer('i_id_room')->requirePresence('i_id_room', 'create')->notEmptyString('i_id_room')
            ->integer('i_reservation_type')->requirePresence('i_reservation_type', 'create')->notEmptyString('i_reservation_type')
            ->integer('i_approval_status')->inList('i_approval_status', [1, 2, 3])->requirePresence('i_approval_status', 'create')
            ->integer('i_approver_id')->requirePresence('i_approver_id', 'create')->notEmptyString('i_approver_id')
            ->scalar('c_reject_reason')->maxLength('c_reject_reason', 255)->allowEmptyString('c_reject_reason')
            ->dateTime('dt_create')->requirePresence('dt_create', 'create');

        return $validator;
    }
}
