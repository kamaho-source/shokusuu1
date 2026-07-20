<?php
declare(strict_types=1);

namespace App\Domain\ValueObject;

/**
 * ユーザーロール定数クラス。
 *
 * i_admin カラムの値とロール判定ロジックを一元管理する。
 * マジックナンバーの散在を防ぎ、ロール追加時の修正箇所を最小化する。
 *
 * ロール体系:
 *   GENERAL (0)       - 一般利用者（居住者・職員）
 *   ADMIN (1)         - 施設管理者（facility_admin）
 *   BLOCK_LEADER (2)  - ブロック長（担当部屋・承認権限）
 *   SYSTEM_ADMIN (3)  - SaaSプラットフォーム管理者（platform_admin）
 *   TENANT_ADMIN (4)  - テナント管理者（法人内の全施設を管理）
 */
final class UserRole
{
    public const GENERAL      = 0;
    public const ADMIN        = 1;
    public const BLOCK_LEADER = 2;
    public const SYSTEM_ADMIN = 3;
    public const TENANT_ADMIN = 4;

    /**
     * 施設管理者レベル以上かどうかを返す。
     *
     * ADMIN / TENANT_ADMIN / SYSTEM_ADMIN はすべて施設データへの管理権限を持つ。
     */
    public static function isAdmin(int $value): bool
    {
        return in_array($value, [self::ADMIN, self::TENANT_ADMIN, self::SYSTEM_ADMIN], true);
    }

    /** ブロック長かどうかを返す。 */
    public static function isBlockLeader(int $value): bool
    {
        return $value === self::BLOCK_LEADER;
    }

    /** SaaSプラットフォーム管理者かどうかを返す。 */
    public static function isSystemAdmin(int $value): bool
    {
        return $value === self::SYSTEM_ADMIN;
    }

    /** テナント管理者（法人内の全施設管理者）かどうかを返す。 */
    public static function isTenantAdmin(int $value): bool
    {
        return $value === self::TENANT_ADMIN;
    }

    private function __construct() {}
}
