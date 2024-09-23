<?php
namespace App\Model\Table;

use Cake\ORM\Query\SelectQuery;
use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

class MRoomInfoTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('m_room_info');
        $this->setDisplayField('i_id_room');
        $this->setPrimaryKey('i_id_room');

        // MUserGroup モデルとの関連付けを設定
        $this->hasMany('MUserGroup', [
            'foreignKey' => 'i_id_room',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->scalar('c_room_name')
            ->maxLength('c_room_name', 50)
            ->allowEmptyString('c_room_name');

        $validator
            ->integer('i_disp_no')
            ->allowEmptyString('i_disp_no');

        $validator
            ->allowEmptyString('i_enable');

        $validator
            ->allowEmptyString('i_del_flg');

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
