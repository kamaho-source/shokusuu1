<?php
declare(strict_types=1);

namespace App\Application\Query;

use App\Application\Query\Dto\RoomMemberView;

/**
 * 部屋・所属の読み取り（画面の選択肢・権限の判定・部屋のメンバー一覧）。
 *
 * 「所属している」は所属中（RoomMembershipStatus::ACTIVE）の所属。読み取りに失敗したときに空を返すメソッドは、その旨を書く。
 */
interface RoomQueryInterface
{
    /**
     * 利用者が所属している部屋（部屋が削除済みでも含む）。読み取りに失敗したら空。
     *
     * @return list<int>
     */
    public function getUserRoomIds(int $userId): array;

    /** 削除されていない「事務所」の部屋に所属しているか。読み取りに失敗したら false。 */
    public function isOfficeUser(int $userId): bool;

    /** どこかの部屋に所属しているか。 */
    public function hasAnyAffiliation(int $userId): bool;

    /** 所属中で、部屋も削除されていないか（RoomMembershipRepository と同じ判定）。 */
    public function isActiveMember(int $userId, int $roomId): bool;

    /** 部屋が存在し、削除されていないか。 */
    public function isRoomAvailable(int $roomId): bool;

    /**
     * 利用者が所属している、削除されていない部屋（表示順）。管理者も同じ（#821）。
     *
     * @return array<int, string> 部屋ID => 部屋名
     */
    public function getAccessibleRooms(int $userId): array;

    /**
     * 利用者が所属している「事務所」の部屋。読み取りに失敗したら空。
     *
     * @return list<int>
     */
    public function getOfficeRoomIds(int $userId): array;

    /**
     * 指定した部屋のうち、削除されていない部屋（表示順）。読み取りに失敗したら空。
     *
     * @param list<int> $roomIds
     * @return array<int, string> 部屋ID => 部屋名
     */
    public function getRoomsByIds(array $roomIds): array;

    /**
     * 削除されていない全部屋（表示順）。読み取りに失敗したら空。
     *
     * @return array<int, string> 部屋ID => 部屋名
     */
    public function getAllActiveRooms(): array;

    /** 削除されていない部屋の数。 */
    public function countActiveRooms(): int;

    /**
     * 部屋の所属メンバー（所属中で、削除されていない人）。並びは全画面共通の表示順。
     *
     * @return list<RoomMemberView>
     */
    public function getRoomMembers(int $roomId): array;
}
