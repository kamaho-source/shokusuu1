<?php
declare(strict_types=1);

namespace App\Domain\Entity;

/**
 * 管理画面で権限などを変える対象の利用者。
 */
final class UserAccount
{
    /**
     * @param int|null $role         i_admin（UserRole の値。未設定なら null）
     * @param int|null $reportAccess i_report_access
     */
    public function __construct(
        public readonly int $id,
        public readonly ?string $name,
        public readonly ?int $role,
        public readonly ?int $reportAccess,
    ) {}
}
