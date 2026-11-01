<?php
declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Repository\RoomMembershipRepositoryInterface;
use App\Infrastructure\Persistence\SystemError;
use Cake\ORM\TableRegistry;

/**
 * RoomMembershipRepositoryInterface の CakePHP 実装（m_user_group・m_room_info）。
 *
 * 同じインスタンスの中では結果を覚えておき、行ごとの再問い合わせを避ける（一括保存で同じ判定が続くため）。
 */
final class CakeRoomMembershipRepository implements RoomMembershipRepositoryInterface
{
    /** @var array<string, bool> */
    private array $activeMember = [];

    /** @var array<int, bool> */
    private array $roomAvailable = [];

    public function isActiveMember(int $userId, int $roomId): bool
    {
        if ($userId <= 0 || $roomId <= 0) {
            return false;
        }

        return $this->activeMember[$userId . ':' . $roomId] ??= $this->read(
            fn(): bool => TableRegistry::getTableLocator()->get('MUserGroup')->find()
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
                ->count() > 0
        );
    }

    public function isRoomAvailable(int $roomId): bool
    {
        if ($roomId <= 0) {
            return false;
        }

        return $this->roomAvailable[$roomId] ??= $this->read(
            fn(): bool => TableRegistry::getTableLocator()->get('MRoomInfo')->find()
                ->where(['i_id_room' => $roomId, 'i_del_flg' => 0])
                ->count() > 0
        );
    }

    public function roomNames(array $roomIds): array
    {
        if ($roomIds === []) {
            return [];
        }
        $rows = TableRegistry::getTableLocator()->get('MRoomInfo')->find()
            ->enableAutoFields(false)
            ->select(['i_id_room', 'c_room_name'])
            ->where(['i_id_room IN' => $roomIds])
            ->all();

        $names = [];
        foreach ($rows as $row) {
            if ($row->c_room_name !== null) {
                $names[(int)$row->i_id_room] = (string)$row->c_room_name;
            }
        }

        return $names;
    }

    /**
     * @param callable(): bool $query
     */
    private function read(callable $query): bool
    {
        try {
            return $query();
        } catch (\Throwable $e) {
            throw SystemError::reservationUpdateFailed($e, '部屋・所属の読み取り');
        }
    }
}
