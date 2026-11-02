<?php
declare(strict_types=1);

namespace App\Domain\Service;

/**
 * 利用者の一括登録（CSV など）の値の読み方。表記ゆれを、保存するコードに直す。
 */
final class UserImportRule
{
    /**
     * 役割名 → i_user_level（0:職員, 1:児童, 3:その他）へ正規化
     */
    public static function normalizeRole(string $raw): ?int
    {
        $v = trim($raw);
        if ($v === '') {
            return null;
        }
        if (function_exists('mb_convert_kana')) {
            $v = mb_convert_kana($v, 'nas', 'UTF-8');
        }
        $vLower = mb_strtolower($v, 'UTF-8');

        if (is_numeric($vLower)) {
            $n = (int)$vLower;
            return in_array($n, [0, 1, 3], true) ? $n : null;
        }

        $exactMap = [
            'staff' => 0, '職員' => 0, 'スタッフ' => 0, '教職員' => 0,
            'child' => 1, 'user' => 1, '利用者' => 1, '児童' => 1,
            'こども' => 1, '子ども' => 1, '子供' => 1, '生徒' => 1, 'ユーザー' => 1,
            'other' => 3, 'その他' => 3, '外部' => 3, 'ゲスト' => 3, '臨時' => 3, 'ボランティア' => 3,
        ];
        if (array_key_exists($vLower, $exactMap)) {
            return $exactMap[$vLower];
        }

        $containsAny = function (string $haystack, array $needles): bool {
            foreach ($needles as $needle) {
                if ($needle !== '' && mb_strpos($haystack, $needle, 0, 'UTF-8') !== false) {
                    return true;
                }
            }
            return false;
        };

        if ($containsAny($vLower, ['職員', 'スタッフ', '教職員', 'staff'])) return 0;
        if ($containsAny($vLower, ['児童', '子ども', '子供', 'こども', '生徒', '利用者', 'ユーザー', 'child', 'user'])) return 1;
        if ($containsAny($vLower, ['その他', '外部', 'ゲスト', '臨時', 'other'])) return 3;

        return null;
    }

    /**
     * 性別の表記 → コード（1:男性, 2:女性）へ正規化。読めなければ null。
     */
    public static function normalizeGender(string $raw): ?int
    {
        if ($raw === '') {
            return null;
        }
        $g = mb_strtolower(trim($raw), 'UTF-8');
        if (is_numeric($g)) {
            $gi = (int)$g;
            return in_array($gi, [1, 2], true) ? $gi : null;
        }
        $genderVal = null;
        if (in_array($g, ['1', '男', '男性', 'male', 'm'], true)) {
            $genderVal = 1;
        }
        if (in_array($g, ['2', '女', '女性', 'female', 'f'], true)) {
            $genderVal = 2;
        }

        return $genderVal;
    }

    /**
     * 年代（age_group）表記 → コード（1..7）へ正規化
     * 1:3~5才, 2:低学年, 3:中学年, 4:高学年, 5:中学生, 6:高校生, 7:大人
     */
    public static function normalizeAgeGroup(string $raw): ?int
    {
        $v = trim($raw);
        if ($v === '') {
            return null;
        }

        if (is_numeric($v)) {
            $n = (int)$v;
            return ($n >= 1 && $n <= 7) ? $n : null;
        }

        if (function_exists('mb_convert_kana')) {
            $v = mb_convert_kana($v, 'as', 'UTF-8');
        }
        $v      = str_replace(['歳', '才', '　'], ['', '', ''], $v);
        $vLower = mb_strtolower($v, 'UTF-8');

        $pairs = [
            ['3~5', 1], ['3-5', 1], ['3〜5', 1], ['3～5', 1],
            ['低学年', 2],
            ['中学年', 3],
            ['高学年', 4],
            ['中学生', 5],
            ['高校生', 6],
            ['大人',   7], ['成人', 7],
        ];
        foreach ($pairs as [$key, $code]) {
            if (mb_strpos($vLower, $key, 0, 'UTF-8') !== false) {
                return $code;
            }
        }

        return null;
    }
}
