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
            ->integer('i_id_user', 'ユーザーの指定が正しくありません。')
            ->requirePresence('i_id_user', 'create', 'ユーザーを指定してください。')
            ->notEmptyString('i_id_user', 'ユーザーを指定してください。');

        $validator
            ->integer('i_id_room_to', '異動先部屋の指定が正しくありません。')
            ->requirePresence('i_id_room_to', 'create', '異動先部屋を指定してください。')
            ->notEmptyString('i_id_room_to', '異動先部屋を指定してください。');

        $validator
            ->date('d_effective_date', ['ymd'], '有効開始日は YYYY-MM-DD の正しい日付で入力してください。')
            ->requirePresence('d_effective_date', 'create', '有効開始日を入力してください。')
            ->notEmptyDate('d_effective_date', '有効開始日を入力してください。');

        $validator
            ->integer('i_status', '状態の指定が正しくありません。')
            ->inList('i_status', [self::STATUS_PENDING, self::STATUS_APPLIED, self::STATUS_CANCELLED], '状態の指定が正しくありません。');

        return $validator;
    }
}
