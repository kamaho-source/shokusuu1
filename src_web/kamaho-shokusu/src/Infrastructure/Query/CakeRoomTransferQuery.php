<?php
declare(strict_types=1);

namespace App\Infrastructure\Query;

use App\Application\Query\Dto\RoomTransferView;
use App\Application\Query\RoomTransferQueryInterface;
use App\Infrastructure\Persistence\Table\MUserGroupTable;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;

/**
 * RoomTransferQueryInterface の CakePHP 実装（m_room_transfer_schedule・m_user_info・m_room_info・m_user_group）。
 */
final class CakeRoomTransferQuery implements RoomTransferQueryInterface
{
    private static function table(string $alias): Table
    {
        return TableRegistry::getTableLocator()->get($alias);
    }

    public function schedules(?int $status): array
    {
        $query = self::table('MRoomTransferSchedule')->find()
            ->contain(['MUserInfo', 'RoomFrom', 'RoomTo'])
            ->orderByDesc('MRoomTransferSchedule.d_effective_date');
        if ($status !== null) {
            $query->where(['MRoomTransferSchedule.i_status' => $status]);
        }

        $out = [];
        foreach ($query->all() as $s) {
            $out[] = new RoomTransferView(
                (int)$s->i_id,
                $s->m_user_info?->c_user_name,
                $s->room_from?->c_room_name,
                $s->room_to?->c_room_name,
                $s->d_effective_date->format('Y-m-d'),
                (int)$s->i_status,
                $s->c_create_user,
                $s->dt_create?->format('Y-m-d H:i:s'),
            );
        }

        return $out;
    }

    public function selectableUsers(): array
    {
        return self::table('MUserInfo')->find('list', keyField: 'i_id_user', valueField: 'c_user_name')
            ->where(['i_del_flag' => 0])
            ->orderByAsc('i_disp_no')
            ->toArray();
    }

    public function selectableRooms(): array
    {
        return self::table('MRoomInfo')->find('list', keyField: 'i_id_room', valueField: 'c_room_name')
            ->where(['i_del_flg' => 0])
            ->toArray();
    }

    public function activeRoomsByUser(): array
    {
        $map = [];
        foreach (self::table('MUserGroup')->find()
            ->select(['i_id_user', 'i_id_room'])
            ->where(MUserGroupTable::activeCondition(''))
            ->all() as $row) {
            $map[(int)$row->i_id_user][] = (int)$row->i_id_room;
        }

        return $map;
    }
}
