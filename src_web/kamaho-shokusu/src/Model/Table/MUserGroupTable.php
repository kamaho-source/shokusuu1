<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class MUserGroupTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('m_user_group');
        $this->setPrimaryKey(['i_id_user', 'i_id_room']);
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
            ->notEmptyString('i_id_user', 'ユーザーIDを入力してください。');

        $validator
            ->integer('i_id_room')
            ->requirePresence('i_id_room', 'create')
            ->notEmptyString('i_id_room', '部屋IDを入力してください。');

        $validator
            ->integer('active_flag')
            ->requirePresence('active_flag', 'create')
            ->notEmptyString('active_flag', 'アクティブフラグを入力してください。')
            ->inList('active_flag', [0, 1], 'アクティブフラグは0または1を指定してください.');

        $validator
            ->dateTime('dt_create')
            ->allowEmptyDateTime('dt_create')
            ->notEmptyDateTime('dt_create', '作成日時を入力してください。');

        $validator
            ->scalar('c_create_user')
            ->maxLength('c_create_user', 50)
            ->allowEmptyString('c_create_user')
            ->notEmptyString('c_create_user', '作成ユーザー名を入力してください。');

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
?>
