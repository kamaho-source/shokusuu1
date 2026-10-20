<?php
declare(strict_types=1);

namespace App\Domain\ValueObject;

/**
 * ユーザーロール定数クラス。
 *
 * i_admin カラムの値とロール判定ロジックを一元管理する。
 * マジックナンバーの散在を防ぎ、ロール追加時の修正箇所を最小化する。
 */
final class UserRole
{
    public const GENERAL      = 0;
    public const ADMIN        = 1;
    public const BLOCK_LEADER = 2;
    public const SYSTEM_ADMIN = 3;

    /** 管理者またはシステム管理者かどうかを返す。 */
    public static function isAdmin(int $value): bool
    {
        return in_array($value, [self::ADMIN, self::SYSTEM_ADMIN], true);
    }

    /** ブロック長かどうかを返す。 */
    public static function isBlockLeader(int $value): bool
    {
        return $value === self::BLOCK_LEADER;
    }

    /**
     * ユーザー登録（個別・一括）ができるロールかを返す。管理者・システム管理者・ブロック長・職員(i_user_level=0)。
     * 権限判定（MUserInfoPolicy）とメニュー表示（レイアウト）の両方がこれを使う。
     * i_user_level が未設定(NULL)のときは職員とみなさない。
     */
    public static function canRegisterUsers(int $iAdmin, ?int $userLevel): bool
    {
        return self::isAdmin($iAdmin) || self::isBlockLeader($iAdmin) || $userLevel === 0;
    }

    /** システム管理者かどうかを返す。 */
    public static function isSystemAdmin(int $value): bool
    {
        return $value === self::SYSTEM_ADMIN;
    }

    private function __construct() {}
}
