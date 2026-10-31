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

    /** 職員として扱う i_user_level（0 と 7。予約まわりの職員判定と同じ）。 */
    public const STAFF_LEVELS = [0, 7];

    /** i_user_level が職員（0 または 7）かどうかを返す。 */
    public static function isStaffLevel(int $userLevel): bool
    {
        return in_array($userLevel, self::STAFF_LEVELS, true);
    }

    /**
     * i_user_level の区分名（部屋のメンバー一覧で使う）。未設定(NULL)は「未設定」、職員(0・7)は「職員」、1 は「児童」、それ以外は「その他」。
     */
    public static function levelLabel(?int $userLevel): string
    {
        return match (true) {
            $userLevel === null => '未設定',
            self::isStaffLevel($userLevel) => '職員',
            $userLevel === 1 => '児童',
            default => 'その他',
        };
    }

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
     * ユーザー登録（個別・一括）ができるロールかを返す。管理者・システム管理者・ブロック長・職員(i_user_level=0 と 7)。
     * 権限判定（MUserInfoPolicy）とメニュー表示（レイアウト）の両方がこれを使う。
     * i_user_level が未設定(NULL)のときは職員とみなさない。
     */
    public static function canRegisterUsers(int $iAdmin, ?int $userLevel): bool
    {
        return self::isAdmin($iAdmin) || self::isBlockLeader($iAdmin) || in_array($userLevel, self::STAFF_LEVELS, true);
    }

    /** システム管理者かどうかを返す。 */
    public static function isSystemAdmin(int $value): bool
    {
        return $value === self::SYSTEM_ADMIN;
    }

    private function __construct() {}
}
