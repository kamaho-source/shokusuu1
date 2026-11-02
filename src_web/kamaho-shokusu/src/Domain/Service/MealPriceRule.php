<?php
declare(strict_types=1);

namespace App\Domain\Service;

/**
 * 単価マスタの入力の決まり（画面の入力欄の決まりと同じ）。
 *
 * - 年（暦年）は必須で 4 桁。同じ年の単価は 1 行だけ（控除表がどれを使うか決まらなくなるため）
 * - 朝・昼・夕・弁当の単価は必須で 0 以上の整数（円）
 */
final class MealPriceRule
{
    /**
     * 入力の問題（利用者に出す文言）。問題が無ければ null。
     *
     * @param array<string, string> $values 'year'・'morning'・'lunch'・'dinner'・'bento' => 入力（空文字は未入力）
     */
    public static function error(array $values): ?string
    {
        $year = $values['year'] ?? '';
        if (preg_match('/^\d{4}$/', $year) !== 1) {
            return '年度は 4 桁の数字で入力してください。';
        }
        foreach (['morning' => '朝食', 'lunch' => '昼食', 'dinner' => '夕食', 'bento' => '弁当'] as $key => $label) {
            if (preg_match('/^\d+$/', $values[$key] ?? '') !== 1) {
                return $label . 'の単価は 0 以上の整数（円）で入力してください。';
            }
        }

        return null;
    }

    public static function duplicateYearMessage(int $year): string
    {
        return $year . '年の単価はすでに登録されています。登録済みの単価を編集してください。';
    }

    public static function missingYearMessage(int $year): string
    {
        return $year . '年の単価が登録されていません。単価マスタに登録してから出力してください。';
    }

    private function __construct() {}
}
