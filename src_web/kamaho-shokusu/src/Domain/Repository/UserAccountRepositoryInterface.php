<?php
declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\UserAccount;

/**
 * 管理画面で利用者の権限などを読み書きする。
 *
 * DB の失敗（接続断など）は変換せずにそのまま投げる。
 */
interface UserAccountRepositoryInterface
{
    /** 利用者。居なければ null（削除済みも含めて探す）。 */
    public function find(int $userId): ?UserAccount;

    /**
     * 権限（i_admin）を変える。保存できなければ false（利用者が居ない場合も false）。
     */
    public function updateRole(int $userId, int $role, string $updatedBy): bool;

    /**
     * レポートの閲覧権（i_report_access）を変える。保存できなければ false。
     */
    public function updateReportAccess(int $userId, int $reportAccess, string $updatedBy): bool;

    /**
     * 削除済みにする（i_del_flag=1・ログイン不可）。保存できなければ false。
     *
     * @param string|null $at 更新日時（'Y-m-d H:i:s'）。省略なら今。削除で外す所属と同じ時刻にそろえるときに渡す
     */
    public function markDeleted(int $userId, string $updatedBy, ?string $at = null): bool;

    /**
     * 削除を取り消す（i_del_flag=0・ログイン可）。保存できなければ false。
     */
    public function markRestored(int $userId, string $updatedBy): bool;

    /**
     * 今所属している部屋（active_flag=0）を、すべて退出済み（1）にする。1行ずつ保存し、失敗しても続ける（従来どおり）。
     */
    public function leaveAllRooms(int $userId, string $updatedBy): void;

    /**
     * 利用者の削除のために、今所属している部屋（active_flag=0）をすべて退出済みにし、「削除で外した」印を付ける。
     * 1行でも保存できなければ false（そこで止める。取り消しは呼び出し側のトランザクションで行う）。
     *
     * @param string $at 更新日時（'Y-m-d H:i:s'）。利用者を削除済みにする時刻と同じにする
     */
    public function leaveAllRoomsForDeletion(int $userId, string $updatedBy, string $at): bool;

    /**
     * 削除で外した所属の部屋 ID（DeletedMembershipRule で見分ける）。復元・「削除前の所属」の表示に使う。
     *
     * @return list<int>
     */
    public function roomsLeftByDeletion(int $userId): array;

    /**
     * 削除済みで印の無い人（印の列を足す前に削除された人）の「削除で外した所属」に、時刻で見分けて印を付ける。
     * 利用者の行を更新すると時刻で見分けられなくなるため、削除済みの人を更新する前に呼ぶ。印がある人・削除されていない人には何もしない。
     */
    public function freezeLeftByDeletion(int $userId): void;

    /**
     * 指定した部屋の所属を所属中に戻し、「削除で外した」印を消す。1行でも保存できなければ false（そこで止める）。
     *
     * @param list<int> $roomIds
     */
    public function rejoinRooms(int $userId, array $roomIds, string $updatedBy): bool;

    /**
     * 部屋に所属させる（新しい行を作る）。保存できなければ false。
     */
    public function joinRoom(int $userId, int $roomId, string $createdBy): bool;
}
