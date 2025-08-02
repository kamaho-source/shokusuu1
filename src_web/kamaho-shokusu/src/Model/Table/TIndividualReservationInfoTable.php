<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\ORM\RulesChecker;
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
            'joinType'   => 'INNER',
        ]);
        $this->belongsTo('MUserInfo', [
            'foreignKey' => 'i_id_user',
            'joinType'   => 'INNER',
        ]);
        $this->belongsTo('MUserGroup', [
            'foreignKey' => ['i_id_user', 'i_id_room'],
            'joinType'   => 'INNER',
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
            ->notEmptyString('i_change_flag', 'Change flag is required');

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

        // 重複登録を防ぐバリデーション（同一ユーザー・日付・区分での多重登録防止）
        $validator->add('d_reservation_date', 'uniqueReservation', [
            'rule' => function ($value, $context) {
                $conditions = [
                    'i_id_user'          => $context['data']['i_id_user'],
                    'd_reservation_date' => $value,
                    'i_reservation_type' => $context['data']['i_reservation_type'],
                ];

                // 既存のレコードを除外する（編集の場合）
                if (!empty($context['data']['i_id_room'])) {
                    $conditions['i_id_room !='] = $context['data']['i_id_room'];
                }

                return $this->find()->where($conditions)->count() === 0;
            },
            'message' => 'This reservation already exists for the given date and meal type.'
        ]);

        // ─────────────────────────────────────────────
        // 「昼(2)」と「弁当(4)」を同一グループと見なした重複チェック
        // ─────────────────────────────────────────────
        $validator->add('d_reservation_date', 'uniqueLunchOrBento', [
            'rule' => function ($value, $context) {
                $data = $context['data'] ?? [];

                // 必要なキーが未入力なら他のバリデーションに任せる
                foreach (['i_id_user', 'i_reservation_type'] as $k) {
                    if (!isset($data[$k])) {
                        return true;
                    }
                }

                $type  = (int)$data['i_reservation_type'];
                $types = in_array($type, [2, 4], true) ? [2, 4] : [$type];

                $conditions = [
                    'i_id_user'             => $data['i_id_user'],
                    'd_reservation_date'    => $value,
                    'i_reservation_type IN' => $types,
                ];

                // 既存レコード更新時は自分自身を除外
                if (!empty($data['i_id_room'])) {
                    $conditions['i_id_room !='] = $data['i_id_room'];
                }

                return !$this->exists($conditions);
            },
            'message' => '同じ日付で「昼」または「弁当」は重複して登録できません。'
        ]);

        return $validator;
    }

    /**
     * RulesChecker による一意性保証
     *
     * 同じユーザーが同じ日付で「昼／弁当」を 1 部屋しか予約できないよう制限します。
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        $rules->add(
            function ($entity) {
                // 食事を取らない行（eat_flag = 0）は重複チェックの対象外
                if ((int)$entity->eat_flag === 0) {
                    return true;
                }

                // 「昼(2)」と「弁当(4)」は同一グループ扱い
                $types = in_array((int)$entity->i_reservation_type, [2, 4], true)
                    ? [2, 4]
                    : [$entity->i_reservation_type];

                $conditions = [
                    'i_id_user'             => $entity->i_id_user,
                    'd_reservation_date'    => $entity->d_reservation_date,
                    'i_reservation_type IN' => $types,
                    'eat_flag'              => 1,
                ];

                // 更新時は自分のレコードを除外
                if (!$entity->isNew()) {
                    $conditions['i_id_room !='] = $entity->i_id_room;
                }

                return !$this->exists($conditions);
            },
            'uniqueDayMeal',
            [
                'errorField' => 'i_id_room',
                'message'    => '同じ日付で「昼」または「弁当」は 1 部屋のみ予約できます。'
            ]
        );

        return $rules;
    }
}