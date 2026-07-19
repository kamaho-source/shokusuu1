<?php
declare(strict_types=1);

namespace App\Model\Table;

use Cake\ORM\Table;
use Cake\Validation\Validator;

class MRoomTransferScheduleTable extends Table
{
    public const STATUS_PENDING   = 0;
    public const STATUS_APPLIED   = 1;
    public const STATUS_CANCELLED = 2;

    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('m_room_transfer_schedule');
        $this->setPrimaryKey('i_id');

        $this->belongsTo('MUserInfo', [
            'foreignKey' => 'i_id_user',
            'joinType'   => 'INNER',
        ]);
        $this->belongsTo('RoomFrom', [
            'className'  => 'MRoomInfo',
            'foreignKey' => 'i_id_room_from',
            'joinType'   => 'LEFT',
        ]);
        $this->belongsTo('RoomTo', [
            'className'  => 'MRoomInfo',
            'foreignKey' => 'i_id_room_to',
            'joinType'   => 'INNER',
        ]);
    }

    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->integer('i_id_user')
            ->requirePresence('i_id_user', 'create')
            ->notEmptyString('i_id_user');

        $validator
            ->integer('i_id_room_to')
            ->requirePresence('i_id_room_to', 'create')
            ->notEmptyString('i_id_room_to');

        $validator
            ->date('d_effective_date')
            ->requirePresence('d_effective_date', 'create')
            ->notEmptyDate('d_effective_date');

        $validator
            ->integer('i_status')
            ->inList('i_status', [self::STATUS_PENDING, self::STATUS_APPLIED, self::STATUS_CANCELLED]);

        return $validator;
    }
}
