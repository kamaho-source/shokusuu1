<?php
declare(strict_types=1);

namespace App\Domain\ValueObject;

/**
 * 利用者アカウントの有効・無効（m_user_info.i_del_flag と i_enable）。
 *
 * 「無効なアカウント（ログインさせない・ログイン中なら締め出す）」の判定はここだけで決める。
 * i_enable は名前と逆で、1 が無効・0 が有効（sql/updates/20260411_005 参照）。
 * DB の型や読み方で整数・文字列・真偽値のどれで来ても同じ結果になるよう、整数にしてから比べる。
 */
final class AccountStatus
{
    /** i_del_flag・i_enable の「削除済み・無効」を表す値 */
    public const DISABLED = 1;

    /**
     * 削除済み（i_del_flag=1）または無効（i_enable=1）か。未設定（null）は有効とみなす。
     */
    public static function isDisabled(int|string|bool|null $delFlag, int|string|bool|null $enable): bool
    {
        return (int)$delFlag === self::DISABLED || (int)$enable === self::DISABLED;
    }

    private function __construct() {}
}
