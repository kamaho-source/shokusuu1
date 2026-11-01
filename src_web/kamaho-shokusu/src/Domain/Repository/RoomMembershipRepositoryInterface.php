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

    /**
     * 部屋名から部屋 ID を引く（削除済みの部屋も含めて、最初に見つかったもの）。無ければ null。
     *
     * @param list<int>|null $onlyRoomIds 渡すと、その部屋の中だけから探す（部屋名は重複し得るため）
     */
    public function roomIdByName(string $roomName, ?array $onlyRoomIds = null): ?int;

    /**
     * 利用者が今所属している部屋（active_flag=0）。重複なし。
     *
     * @return list<int>
     */
    public function activeRoomIds(int $userId): array;

    /**
     * 選べる部屋（削除されていない部屋）。$onlyRoomIds を渡すと、その中だけ。
     *
     * @param list<int>|null $onlyRoomIds
     * @return array<int, string> 部屋ID => 部屋名
     */
    public function selectableRooms(?array $onlyRoomIds = null): array;
}
