<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * MRoomInfoTable Model
 */
class MRoomInfoTable extends Table
{
    public function initialize(array $config): void
    {
        parent::initialize($config);

        // テーブル名を設定
        $this->setTable('m_room_info');
        $this->setDisplayField('c_room_name');
        $this->setPrimaryKey('i_id_room');
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('i_id_room')
            ->allowEmptyString('i_id_room', null, 'create');

        $validator
            ->scalar('c_room_name')
            ->maxLength('c_room_name', 255)
            ->requirePresence('c_room_name', 'create')
            ->notEmptyString('c_room_name');

        return $validator;
    }
}
