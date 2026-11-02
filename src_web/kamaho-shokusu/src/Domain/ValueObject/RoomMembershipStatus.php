<?php
declare(strict_types=1);

namespace App\Domain\ValueObject;

/**
 * 部屋の所属の状態（m_user_group.active_flag）。値は通常の慣習と逆で、0 が所属中。
 *
 * 「部屋に所属している」の判定はここだけで決める。クエリの条件は MUserGroupTable::activeCondition() を使う。
 */
final class RoomMembershipStatus
{
    /** 所属中 */
    public const ACTIVE = 0;
    /** 退出済み（部屋異動・利用者の削除で外れた） */
    public const LEFT = 1;

    /** 所属中か（従来どおり、NULL は 0 とみなす）。 */
    public static function isActive(int|string|null $activeFlag): bool
    {
        return (int)$activeFlag === self::ACTIVE;
    }

    private function __construct() {}
}
