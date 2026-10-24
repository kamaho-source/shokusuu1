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
            Log::error(sprintf('RoomAccessService#getUserRoomIds failed: %s: %s (userId=%d)', get_class($e), $e->getMessage(), $userId));
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
                    'MRoomInfo.i_del_flg' => 0, // 削除済みの事務所の所属では事務所ユーザーにしない
                ])
                ->count() > 0;
        } catch (\Throwable $e) {
            Log::error(sprintf('RoomAccessService#isOfficeUser failed: %s: %s (userId=%d)', get_class($e), $e->getMessage(), $userId));
            return false;
        }
    }

    public function userCanAccessRoom(int $userId, int $roomId): bool
    {
        if ($userId <= 0 || $roomId <= 0) {
            return false;
        }

        // 画面の選択肢と同じ基準（退出済み・削除済み部屋は不可）
        return $this->isActiveMember($userId, $roomId);
    }

    /**
     * 部屋が存在し、削除されていないか（管理者が所属外の部屋を扱う場合の最低条件）。
     */
    public function isRoomAvailable(int $roomId): bool
    {
        if ($roomId <= 0) {
            return false;
        }

        return TableRegistry::getTableLocator()->get('MRoomInfo')->find()
            ->where(['i_id_room' => $roomId, 'i_del_flg' => 0])
            ->count() > 0;
    }

    /**
     * 有効な所属か（所属が退出済み(active_flag=1)でなく、部屋が削除済み(i_del_flg=1)でもない）。
     *
     * 予約の書き込み可否の判定に使う。画面の選択肢（active_flag=0 の所属のみ）と基準を揃える。
     */
    public function isActiveMember(int $userId, int $roomId): bool
    {
        if ($userId <= 0 || $roomId <= 0) {
            return false;
        }

        return TableRegistry::getTableLocator()->get('MUserGroup')->find()
            ->innerJoin(
                ['MRoomInfo' => 'm_room_info'],
                ['MRoomInfo.i_id_room = MUserGroup.i_id_room']
            )
            ->where([
                'MUserGroup.i_id_user'   => $userId,
                'MUserGroup.i_id_room'   => $roomId,
                'MUserGroup.active_flag' => 0,
                'MRoomInfo.i_del_flg'    => 0,
            ])
            ->count() > 0;
    }

    public function hasAnyAffiliation(int $userId): bool
    {
        return !empty($this->getUserRoomIds($userId));
    }

    public function getAccessibleRooms(Table $roomTable, int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $rows = $roomTable->find()
            ->enableAutoFields(false)
            ->select([
                'room_id' => 'MRoomInfo.i_id_room',
                'room_name' => 'MRoomInfo.c_room_name',
                'disp_no' => 'MRoomInfo.i_disp_no',
            ])
            ->innerJoin(
                ['MUserGroup' => 'm_user_group'],
                ['MUserGroup.i_id_room = MRoomInfo.i_id_room']
            )
            ->where([
                'MUserGroup.i_id_user' => $userId,
                'MUserGroup.active_flag' => 0,
                'MRoomInfo.i_del_flg' => 0,
            ])
            ->distinct(['MRoomInfo.i_id_room', 'MRoomInfo.c_room_name', 'MRoomInfo.i_disp_no'])
            // 既定の部屋（先頭）と選択肢の並びを、他の部屋一覧と同じ表示順に固定する
            ->orderBy(['MRoomInfo.i_disp_no' => 'ASC', 'MRoomInfo.i_id_room' => 'ASC'])
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
            Log::error(sprintf('RoomAccessService#getOfficeRoomIds failed: %s: %s (userId=%d)', get_class($e), $e->getMessage(), $userId));
            return [];
        }
    }

    /**
     * 指定IDの部屋一覧を取得する（ブロック長用）。
     *
     * @param array<int> $roomIds
     * @return array<int, string>
     */
    public function getRoomsByIds(array $roomIds): array
    {
        if (empty($roomIds)) {
            return [];
        }

        try {
            $table = TableRegistry::getTableLocator()->get('MRoomInfo');
            return $table->find('list', ['keyField' => 'i_id_room', 'valueField' => 'c_room_name'])
                ->where(['i_id_room IN' => $roomIds, 'i_del_flg' => 0])
                ->orderBy(['i_disp_no' => 'ASC'])
                ->toArray();
        } catch (\Throwable $e) {
            Log::error(sprintf('RoomAccessService#getRoomsByIds failed: %s: %s (roomIds=%s)', get_class($e), $e->getMessage(), implode(',', $roomIds)));
            return [];
        }
    }

    /**
     * 有効な全部屋一覧を取得する（管理者用）。
     *
     * @return array<int, string>
     */
    public function getAllActiveRooms(): array
    {
        try {
            $table = TableRegistry::getTableLocator()->get('MRoomInfo');
            return $table->find('list', ['keyField' => 'i_id_room', 'valueField' => 'c_room_name'])
                ->where(['i_del_flg' => 0])
                ->orderBy(['i_disp_no' => 'ASC'])
                ->toArray();
        } catch (\Throwable $e) {
            Log::error(sprintf('RoomAccessService#getAllActiveRooms failed: %s: %s', get_class($e), $e->getMessage()));
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
