<?php
declare(strict_types=1);

namespace App\Domain\Service;

/**
 * パスワードを変えるときのルール。
 */
final class PasswordRule
{
    /** パスワードの最短の長さ。 */
    public const MIN_LENGTH = 2;

    /**
     * 新しいパスワードの誤り。問題なければ null。
     *
     * @param bool $countBytes 長さをバイト数で数えるか（管理者による変更は従来どおりバイト数、本人の変更は文字数）
     */
    public static function changeError(string $newPassword, string $confirmPassword, bool $countBytes = false): ?string
    {
        if ($newPassword !== $confirmPassword) {
            return '新しいパスワードが一致しません。';
        }
        $length = $countBytes ? strlen($newPassword) : mb_strlen($newPassword);
        if ($length < self::MIN_LENGTH) {
            return '新しいパスワードは2文字以上にしてください。';
        }

        return null;
    }
}
