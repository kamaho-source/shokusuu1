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
     */
    public function markDeleted(int $userId, string $updatedBy): bool;

    /**
     * 削除を取り消す（i_del_flag=0・ログイン可）。保存できなければ false。
     */
    public function markRestored(int $userId, string $updatedBy): bool;

    /**
     * 今所属している部屋（active_flag=0）を、すべて退出済み（1）にする。1行ずつ保存し、失敗しても続ける（従来どおり）。
     */
    public function leaveAllRooms(int $userId, string $updatedBy): void;

    /**
     * 所属の行をすべて（退出済みも含めて）所属中に戻す。1行でも保存できなければ false（そこで止める）。
     */
    public function rejoinAllRooms(int $userId, string $updatedBy): bool;

    /**
     * 部屋に所属させる（新しい行を作る）。保存できなければ false。
     */
    public function joinRoom(int $userId, int $roomId, string $createdBy): bool;
}
