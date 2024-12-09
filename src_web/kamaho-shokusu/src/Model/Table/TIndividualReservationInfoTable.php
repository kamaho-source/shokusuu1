<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class TIndividualReservationInfoTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('t_individual_reservation_info');
        $this->setDisplayField('i_id_user');
        $this->setPrimaryKey(['i_id_user', 'd_reservation_date', 'i_id_room', 'i_reservation_type']);

        $this->belongsTo('MRoomInfo', [
            'foreignKey' => 'i_id_room',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('MUserInfo', [
            'foreignKey' => 'i_id_user',
            'joinType' => 'INNER',
        ]);
        $this->belongsTo('MUserGroup', [
            'foreignKey' => ['i_id_user', 'i_id_room'],
            'joinType' => 'INNER',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        // 基本的なバリデーション
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

        // 重複登録を防ぐバリデーション
        $validator->add('d_reservation_date', 'uniqueReservation', [
            'rule' => function ($value, $context) {
                $conditions = [
                    'i_id_user' => $context['data']['i_id_user'],
                    'd_reservation_date' => $value,
                    'i_reservation_type' => $context['data']['i_reservation_type'],
                ];

                // 既存のレコードを除外する（編集の場合）
                if (!empty($context['data']['id'])) {
                    $conditions[] = ['id !=' => $context['data']['id']];
                }

                $count = $this->find()
                    ->where($conditions)
                    ->count();

                return $count === 0;
            },
            'message' => 'This reservation already exists for the given date and meal type.'
        ]);

        return $validator;
    }
}
