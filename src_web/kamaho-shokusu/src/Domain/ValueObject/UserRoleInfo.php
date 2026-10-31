<?php
declare(strict_types=1);

namespace App\Domain\ValueObject;

/**
 * 予約の権限判定に使う、利用者のロール情報（m_user_info の i_admin・i_user_level）。
 */
final class UserRoleInfo
{
    public function __construct(
        public readonly int $admin,
        public readonly ?int $userLevel,
    ) {}

    public function isAdmin(): bool
    {
        return UserRole::isAdmin($this->admin);
    }

    public function isBlockLeader(): bool
    {
        return UserRole::isBlockLeader($this->admin);
    }

    /** 職員（i_user_level が 0 または 7）か。未設定は職員ではない。 */
    public function isStaff(): bool
    {
        return $this->userLevel !== null && UserRole::isStaffLevel($this->userLevel);
    }
}
