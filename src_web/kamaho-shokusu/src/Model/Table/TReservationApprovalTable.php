<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class TReservationApprovalTable extends Table
{
    public const STATUS_PENDING = 0;
    public const STATUS_APPROVED = 1;
    public const STATUS_REJECTED = 2;

    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('t_reservation_approval');
        $this->setDisplayField('i_id_user');
        $this->setPrimaryKey(['i_id_user', 'd_reservation_date', 'i_id_room', 'i_reservation_type']);

        $this->belongsTo('MUserInfo', [
            'foreignKey' => 'i_id_user',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('MRoomInfo', [
            'foreignKey' => 'i_id_room',
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
            ->date('d_reservation_date')
            ->requirePresence('d_reservation_date', 'create')
            ->notEmptyDate('d_reservation_date');

        $validator
            ->integer('i_id_room')
            ->requirePresence('i_id_room', 'create')
            ->notEmptyString('i_id_room');

        $validator
            ->integer('i_reservation_type')
            ->requirePresence('i_reservation_type', 'create')
            ->notEmptyString('i_reservation_type');

        $validator
            ->integer('i_requested_flag')
            ->requirePresence('i_requested_flag', 'create')
            ->inList('i_requested_flag', [0, 1]);

        $validator
            ->integer('i_status')
            ->requirePresence('i_status', 'create')
            ->inList('i_status', [self::STATUS_PENDING, self::STATUS_APPROVED, self::STATUS_REJECTED]);

        $validator
            ->scalar('c_reason')
            ->maxLength('c_reason', 255)
            ->allowEmptyString('c_reason');

        $validator->integer('i_reviewer_user')->allowEmptyString('i_reviewer_user');
        $validator->dateTime('dt_reviewed')->allowEmptyDateTime('dt_reviewed');
        $validator->dateTime('dt_create')->allowEmptyDateTime('dt_create');
        $validator->scalar('c_create_user')->maxLength('c_create_user', 50)->allowEmptyString('c_create_user');
        $validator->dateTime('dt_update')->allowEmptyDateTime('dt_update');
        $validator->scalar('c_update_user')->maxLength('c_update_user', 50)->allowEmptyString('c_update_user');

        return $validator;
    }
}
