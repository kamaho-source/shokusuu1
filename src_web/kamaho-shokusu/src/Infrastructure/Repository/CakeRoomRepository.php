<?php
declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Entity\Room;
use App\Domain\Entity\RoomSaveResult;
use App\Domain\Repository\RoomRepositoryInterface;
use App\Service\RoomMemberOrderService;
use Cake\Datasource\EntityInterface;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;

/**
 * RoomRepositoryInterface の CakePHP 実装（m_room_info・m_user_group・m_user_info）。
 *
 * 保存はテーブルの save() を通す（従来どおり、ルール・イベントを通すため）。
 */
final class CakeRoomRepository implements RoomRepositoryInterface
{
    private readonly Table $rooms;
    private readonly Table $users;

    public function __construct(?Table $rooms = null, ?Table $users = null)
    {
        $this->rooms = $rooms ?? TableRegistry::getTableLocator()->get('MRoomInfo');
        $this->users = $users ?? TableRegistry::getTableLocator()->get('MUserInfo');
    }

    public function find(int $roomId): ?Room
    {
        $row = $this->row($roomId);
        if ($row === null) {
            return null;
        }

        return new Room(
            (int)$row->get('i_id_room'),
            $row->get('c_room_name') === null ? null : (string)$row->get('c_room_name'),
            $row->get('i_del_flg') === null ? null : (int)$row->get('i_del_flg'),
        );
    }

    public function nextDisplayNo(): int
    {
        $max = $this->rooms->find()->select(['max_disp_no' => 'MAX(i_disp_no)'])->first()?->get('max_disp_no');

        return (int)($max ?? 0) + 1;
    }

    public function create(array $input, int $displayNo, ?string $createdBy, string $at): RoomSaveResult
    {
        $room = $this->rooms->newEmptyEntity();
        $room->set('dt_create', $at);
        if ($createdBy !== null) {
            $room->set('c_create_user', $createdBy);
        }
        $room->set('i_del_flg', 0);
        $room->set('i_disp_no', $displayNo);
        $room = $this->rooms->patchEntity($room, $input, ['fields' => ['c_room_name']]);
        if ($room->hasErrors()) {
            return RoomSaveResult::failed($room->getErrors());
        }
        if (!$this->rooms->save($room)) {
            return RoomSaveResult::failed($room->getErrors());
        }

        return RoomSaveResult::saved((int)$room->get('i_id_room'));
    }

    public function rename(int $roomId, array $input, string $updatedBy, string $at): RoomSaveResult
    {
        $room = $this->row($roomId);
        if ($room === null) {
            return RoomSaveResult::failed([]);
        }
        $room = $this->rooms->patchEntity($room, $input, ['fields' => ['c_room_name']]);
        if ($room->hasErrors()) {
            return RoomSaveResult::failed($room->getErrors());
        }
        if (!$room->isDirty('c_room_name')) {
            return RoomSaveResult::saved($roomId, false);
        }
        $room->set('dt_update', $at);
        $room->set('c_update_user', $updatedBy);
        if (!$this->rooms->save($room)) {
            return RoomSaveResult::failed($room->getErrors());
        }

        return RoomSaveResult::saved($roomId);
    }

    public function softDelete(int $roomId, ?string $updatedBy, string $at): bool
    {
        $room = $this->row($roomId);
        if ($room === null) {
            return false;
        }
        $room->set('i_del_flg', 1);
        $room->set('c_update_user', $updatedBy);
        $room->set('dt_update', $at);

        return (bool)$this->rooms->save($room);
    }

    public function memberDisplayOrders(int $roomId): array
    {
        $members = RoomMemberOrderService::orderMembers(
            TableRegistry::getTableLocator()->get('MUserGroup')->find()
                ->enableAutoFields(false)
                ->select(['id' => 'MUserInfo.i_id_user', 'disp_no' => 'MUserInfo.i_disp_no'])
                ->innerJoin(['MUserInfo' => 'm_user_info'], ['MUserInfo.i_id_user = MUserGroup.i_id_user'])
                ->where([
                    'MUserGroup.i_id_room'   => $roomId,
                    'MUserGroup.active_flag' => 0,
                    'MUserInfo.i_del_flag'   => 0,
                ])
                ->enableHydration(false)
        )->toArray();

        $orders = [];
        foreach ($members as $m) {
            $orders[(int)$m['id']] = $m['disp_no'] === null ? null : (int)$m['disp_no'];
        }

        return $orders;
    }

    public function maxUserDisplayNo(): int
    {
        return (int)($this->users->find()->select(['m' => 'MAX(i_disp_no)'])->enableHydration(false)->first()['m'] ?? 0);
    }

    public function setUserDisplayNo(int $userId, int $displayNo, string $updatedBy, string $at): void
    {
        $this->users->updateAll(
            ['i_disp_no' => $displayNo, 'dt_update' => $at, 'c_update_user' => $updatedBy],
            ['i_id_user' => $userId]
        );
    }

    private function row(int $roomId): ?EntityInterface
    {
        /** @var \Cake\Datasource\EntityInterface|null */
        return $this->rooms->find()->where(['i_id_room' => $roomId])->first();
    }
}
