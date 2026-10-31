<?php
declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Exception\PersistenceException;

/**
 * 部屋と所属の読み取り。
 *
 * 「有効な所属」= 所属が退出済み（active_flag=1）でなく、部屋が削除済み（i_del_flg=1）でもないこと。
 */
interface RoomMembershipRepositoryInterface
{
    /**
     * 利用者がその部屋に有効に所属しているか。
     *
     * @throws PersistenceException 読み取りに失敗したとき
     */
    public function isActiveMember(int $userId, int $roomId): bool;

    /**
     * 部屋が存在し、削除されていないか。
     *
     * @throws PersistenceException 読み取りに失敗したとき
     */
    public function isRoomAvailable(int $roomId): bool;

    /**
     * 部屋名（削除済みの部屋も含む）。名前の無い・見つからない部屋は含まない。
     *
     * @param list<int> $roomIds
     * @return array<int, string> 部屋ID => 部屋名
     * @throws \RuntimeException 読み取りに失敗したとき
     */
    public function roomNames(array $roomIds): array;
}
