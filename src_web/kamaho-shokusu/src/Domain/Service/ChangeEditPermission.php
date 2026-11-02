<?php
declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\ValueObject\UserRole;

/**
 * 直前編集・一括登録・直前一括編集で、ある利用者の予約を変えてよいか。
 *
 * - 本人の予約は変えられる
 * - 「全員を編集できる人」（管理者・その部屋に所属するブロック長）は全員の予約を変えられる
 * - 職員は他の職員（大人）の予約は変えられない（予約カレンダー・エクセル食数予約と同じ）
 * - 職員は子ども（i_user_level=1）の予約を変えられる
 * - 対象の区分が分からないときは子どもとみなさない（変えられない方に倒す）
 * - 直前期間の職員の予約は、管理者以外は取り消せない・部屋を移せない
 *
 * 部屋への所属（操作する人・対象）は DB を見るため、呼び出し側で確かめる。
 */
final class ChangeEditPermission
{
    /** 全員の予約を変えられるか。 */
    public static function canEditAll(int $loginAdmin, bool $isBlockLeaderInRoom): bool
    {
        return UserRole::isAdmin($loginAdmin) || $isBlockLeaderInRoom;
    }

    public static function canEdit(bool $canEditAll, int $loginUserId, int $loginLevel, int $targetUserId, ?int $targetLevel): bool
    {
        return ($loginUserId > 0 && $loginUserId === $targetUserId)
            || self::canEditOther($canEditAll, UserRole::isStaffLevel($loginLevel), $targetLevel);
    }

    /** 本人以外の予約を変えられるか（全員を編集できる人か、職員が子どもを）。 */
    public static function canEditOther(bool $canEditAll, bool $isLoginStaff, ?int $targetLevel): bool
    {
        return $canEditAll || ($isLoginStaff && $targetLevel === 1);
    }

    /** 直前期間の職員の予約で、取消・部屋の移動を止めるか（管理者は止めない）。 */
    public static function isStaffLockedInLastMinute(bool $isAdmin, bool $isLastMinute, ?int $targetLevel): bool
    {
        return !$isAdmin && $isLastMinute && $targetLevel !== null && UserRole::isStaffLevel($targetLevel);
    }

    private function __construct() {}
}
