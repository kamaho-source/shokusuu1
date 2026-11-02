<?php
declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\ValueObject\UserRole;

/**
 * 直前編集で、ある利用者の予約を変えてよいか。
 *
 * - 本人の予約は変えられる
 * - 「全員を編集できる人」（管理者・部屋の管理者・その部屋に所属するブロック長）は全員の予約を変えられる
 * - 職員は子ども（i_user_level=1）の予約を変えられる
 * - 対象の区分が分からないときは子どもとみなさない（変えられない方に倒す）
 */
final class ChangeEditPermission
{
    /** 全員の予約を変えられるか。 */
    public static function canEditAll(int $loginAdmin, bool $isRoomManager, bool $isBlockLeaderInRoom): bool
    {
        return $isRoomManager || UserRole::isAdmin($loginAdmin) || $isBlockLeaderInRoom;
    }

    public static function canEdit(bool $canEditAll, int $loginUserId, int $loginLevel, int $targetUserId, ?int $targetLevel): bool
    {
        return $canEditAll
            || ($loginUserId > 0 && $loginUserId === $targetUserId)
            || (UserRole::isStaffLevel($loginLevel) && $targetLevel === 1);
    }

    private function __construct() {}
}
