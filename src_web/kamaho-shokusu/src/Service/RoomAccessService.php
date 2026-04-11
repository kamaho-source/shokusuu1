<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Log\Log;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;

class RoomAccessService
{
    private const OFFICE_ROOM_KEYWORD = '事務所';

    /**
     * @return array<int>
     */
    public function getUserRoomIds(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        try {
            $table = TableRegistry::getTableLocator()->get('MUserGroup');
            $rows = $table->find()
                ->select(['i_id_room'])
                ->where(['i_id_user' => $userId, 'active_flag' => 0])
                ->all();

            $roomIds = [];
            foreach ($rows as $row) {
                $roomId = (int)$row->i_id_room;
                if ($roomId > 0) {
                    $roomIds[] = $roomId;
                }
            }

            return array_values(array_unique($roomIds));
        } catch (\Throwable $e) {
            Log::error('RoomAccessService#getUserRoomIds failed: ' . $e->getMessage());
            return [];
        }
    }

    public function isOfficeUser(int $userId): bool
    {
        if ($userId <= 0) {
            return false;
        }

        try {
            $table = TableRegistry::getTableLocator()->get('MUserGroup');

            return $table->find()
                ->innerJoin(
                    ['MRoomInfo' => 'm_room_info'],
                    ['MRoomInfo.i_id_room = MUserGroup.i_id_room']
                )
                ->where([
                    'MUserGroup.i_id_user' => $userId,
                    'MUserGroup.active_flag' => 0,
                    'MRoomInfo.c_room_name LIKE' => '%' . self::OFFICE_ROOM_KEYWORD . '%',
                ])
                ->count() > 0;
        } catch (\Throwable $e) {
            Log::error('RoomAccessService#isOfficeUser failed: ' . $e->getMessage());
            return false;
        }
    }

    public function userCanAccessRoom(int $userId, int $roomId): bool
    {
        if ($userId <= 0 || $roomId <= 0) {
            return false;
        }

        if ($this->isOfficeUser($userId)) {
            return in_array($roomId, $this->getOfficeRoomIds($userId), true);
        }

        return in_array($roomId, $this->getUserRoomIds($userId), true);
    }

    public function getAccessibleRooms(Table $roomTable, int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        if ($this->isOfficeUser($userId)) {
            return $this->getOfficeRooms($roomTable, $userId);
        }

        $rows = $roomTable->find()
            ->enableAutoFields(false)
            ->select([
                'room_id' => 'MRoomInfo.i_id_room',
                'room_name' => 'MRoomInfo.c_room_name',
            ])
            ->innerJoin(
                ['MUserGroup' => 'm_user_group'],
                ['MUserGroup.i_id_room = MRoomInfo.i_id_room']
            )
            ->where([
                'MUserGroup.i_id_user' => $userId,
                'MUserGroup.active_flag' => 0,
            ])
            ->distinct(['MRoomInfo.i_id_room', 'MRoomInfo.c_room_name'])
            ->enableHydration(false)
            ->toArray();

        $rooms = [];
        foreach ($rows as $row) {
            $rooms[(int)$row['room_id']] = (string)$row['room_name'];
        }

        return $rooms;
    }

    /**
     * @return array<int>
     */
    public function getOfficeRoomIds(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        try {
            $table = TableRegistry::getTableLocator()->get('MUserGroup');
            $rows = $table->find()
                ->select(['MUserGroup.i_id_room'])
                ->innerJoin(
                    ['MRoomInfo' => 'm_room_info'],
                    ['MRoomInfo.i_id_room = MUserGroup.i_id_room']
                )
                ->where([
                    'MUserGroup.i_id_user' => $userId,
                    'MUserGroup.active_flag' => 0,
                    'MRoomInfo.c_room_name LIKE' => '%' . self::OFFICE_ROOM_KEYWORD . '%',
                ])
                ->all();

            $roomIds = [];
            foreach ($rows as $row) {
                $roomId = (int)$row->i_id_room;
                if ($roomId > 0) {
                    $roomIds[] = $roomId;
                }
            }

            return array_values(array_unique($roomIds));
        } catch (\Throwable $e) {
            Log::error('RoomAccessService#getOfficeRoomIds failed: ' . $e->getMessage());
            return [];
        }
    }

    private function getOfficeRooms(Table $roomTable, int $userId): array
    {
        $officeRoomIds = $this->getOfficeRoomIds($userId);
        if (empty($officeRoomIds)) {
            return [];
        }

        return $roomTable->find('list', [
            'keyField' => 'i_id_room',
            'valueField' => 'c_room_name',
        ])
            ->where(['i_id_room IN' => $officeRoomIds])
            ->orderBy($this->buildRoomOrder($roomTable))
            ->toArray();
    }

    private function buildRoomOrder(Table $roomTable): array
    {
        $roomOrder = ['i_id_room' => 'ASC'];

        try {
            $schema = $roomTable->getSchema();
            if (method_exists($schema, 'hasColumn')) {
                if ($schema->hasColumn('i_sort')) {
                    $roomOrder = ['i_sort' => 'ASC', 'i_id_room' => 'ASC'];
                } elseif ($schema->hasColumn('display_order')) {
                    $roomOrder = ['display_order' => 'ASC', 'i_id_room' => 'ASC'];
                } elseif ($schema->hasColumn('i_disp_no')) {
                    $roomOrder = ['i_disp_no' => 'ASC', 'i_id_room' => 'ASC'];
                } elseif ($schema->hasColumn('c_room_name')) {
                    $roomOrder = ['c_room_name' => 'ASC', 'i_id_room' => 'ASC'];
                }
            }
        } catch (\Throwable $e) {
            $roomOrder = ['i_id_room' => 'ASC'];
        }

        return $roomOrder;
    }
}
