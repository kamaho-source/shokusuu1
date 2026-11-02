<?php
declare(strict_types=1);

namespace App\Infrastructure\Query;

use App\Application\Query\Dto\RoomMemberView;
use App\Application\Query\RoomQueryInterface;
use App\Infrastructure\Persistence\Table\MUserGroupTable;
use App\Infrastructure\Repository\CakeRoomMembershipRepository;
use App\Service\RoomMemberOrderService;
use Cake\Log\Log;
use Cake\ORM\TableRegistry;

/**
 * RoomQueryInterface の CakePHP 実装（m_room_info・m_user_group・m_user_info の読み取り）。
 */
final class CakeRoomQuery implements RoomQueryInterface
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
            // 削除済みの部屋は所属に数えない（代表部屋が削除済みになると、カレンダーで予約を開けなくなるため）
            $rows = TableRegistry::getTableLocator()->get('MUserGroup')->find()
                ->select(['MUserGroup.i_id_room'])
                ->innerJoin(['MRoomInfo' => 'm_room_info'], ['MRoomInfo.i_id_room = MUserGroup.i_id_room'])
                ->where(['MUserGroup.i_id_user' => $userId, ...MUserGroupTable::activeCondition(), 'MRoomInfo.i_del_flg' => 0])
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
            Log::error(sprintf('CakeRoomQuery#getUserRoomIds failed: %s: %s (userId=%d)', get_class($e), $e->getMessage(), $userId));
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
                    ...MUserGroupTable::activeCondition(),
                    'MRoomInfo.c_room_name LIKE' => '%' . self::OFFICE_ROOM_KEYWORD . '%',
                    'MRoomInfo.i_del_flg' => 0, // 削除済みの事務所の所属では事務所ユーザーにしない
                ])
                ->count() > 0;
        } catch (\Throwable $e) {
            Log::error(sprintf('CakeRoomQuery#isOfficeUser failed: %s: %s (userId=%d)', get_class($e), $e->getMessage(), $userId));
            return false;
        }
    }

    /**
     * 部屋が存在し、削除されていないか（管理者が所属外の部屋を扱う場合の最低条件）。
     */
    public function isRoomAvailable(int $roomId): bool
    {
        // 判定の結果を覚えない新しいインスタンスで問い合わせる（異動の反映直後などに古い結果を返さない）
        return (new CakeRoomMembershipRepository())->isRoomAvailable($roomId);
    }

    /**
     * 有効な所属か（所属中で、部屋が削除済み(i_del_flg=1)でもない）。判定は RoomMembershipRepository に1つだけある。
     *
     * 予約の書き込み可否・部屋に入れるかの判定に使う。画面の選択肢（所属中の部屋のみ）と基準を揃える。
     */
    public function isActiveMember(int $userId, int $roomId): bool
    {
        return (new CakeRoomMembershipRepository())->isActiveMember($userId, $roomId);
    }

    public function hasAnyAffiliation(int $userId): bool
    {
        return !empty($this->getUserRoomIds($userId));
    }

    public function getAccessibleRooms(int $userId): array
    {
        if ($userId <= 0) {
            return [];
        }

        $rows = TableRegistry::getTableLocator()->get('MRoomInfo')->find()
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
                ...MUserGroupTable::activeCondition(),
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
            Log::error(sprintf('CakeRoomQuery#getRoomsByIds failed: %s: %s (roomIds=%s)', get_class($e), $e->getMessage(), implode(',', $roomIds)));
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
            Log::error(sprintf('CakeRoomQuery#getAllActiveRooms failed: %s: %s', get_class($e), $e->getMessage()));
            return [];
        }
    }

    public function countActiveRooms(): int
    {
        return TableRegistry::getTableLocator()->get('MRoomInfo')->find()->where(['i_del_flg' => 0])->count();
    }

    public function getRoomMembers(int $roomId): array
    {
        $userIds = TableRegistry::getTableLocator()->get('MUserGroup')->find()
            ->select(['i_id_user'])
            ->where(['i_id_room' => $roomId, ...MUserGroupTable::activeCondition('')])
            ->all()
            ->extract('i_id_user')
            ->toList();
        if ($userIds === []) {
            return [];
        }

        $members = [];
        foreach (RoomMemberOrderService::orderMembers(TableRegistry::getTableLocator()->get('MUserInfo')->find())
            ->where(['MUserInfo.i_id_user IN' => $userIds, 'MUserInfo.i_del_flag' => 0])
            ->all() as $user) {
            $members[] = new RoomMemberView(
                (int)$user->get('i_id_user'),
                $user->get('c_user_name') === null ? null : (string)$user->get('c_user_name'),
                $user->get('i_user_level') === null ? null : (int)$user->get('i_user_level'),
                $user->get('i_user_age') === null ? null : (int)$user->get('i_user_age'),
                $user->get('i_disp_no') === null ? null : (int)$user->get('i_disp_no'),
            );
        }

        return $members;
    }
}
