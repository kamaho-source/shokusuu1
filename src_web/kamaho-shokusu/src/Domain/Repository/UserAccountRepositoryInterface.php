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
}
