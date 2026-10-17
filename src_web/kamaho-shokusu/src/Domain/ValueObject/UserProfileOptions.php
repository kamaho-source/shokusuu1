<?php
declare(strict_types=1);

namespace App\Domain\ValueObject;

/**
 * ユーザーの性別・年代・役職の選択肢と表示名。
 *
 * 新規登録・詳細・編集の3画面で同じ定義を使うために1か所にまとめる。
 * 値は m_user_info の i_user_gender / i_user_rank / i_user_level にそのまま保存される。
 */
final class UserProfileOptions
{
    public const STAFF_LEVEL = 0;

    /** @var array<int, string> i_user_gender */
    public const GENDERS = [
        1 => '男性',
        2 => '女性',
    ];

    /** @var array<int, string> i_user_rank（年代） */
    public const AGE_GROUPS = [
        1 => '3~5才',
        2 => '低学年',
        3 => '中学年',
        4 => '高学年',
        5 => '中学生',
        6 => '高校生',
        7 => '大人',
    ];

    /** @var array<int, string> i_user_level（役職） */
    public const USER_LEVELS = [
        0 => '職員',
        1 => '児童',
        3 => 'その他',
    ];

    public const MIN_AGE = 1;
    public const MAX_AGE = 80;

    /**
     * 年代が切り替わる年齢 => その年齢からの年代（i_user_rank）。これより若ければ 1（3~5才）。
     * 年度更新バッチ（FiscalRolloverCommand）が年齢を1つ上げるときに年代を進める境目と同じ。
     * 新規登録で生年月日から年代を自動で選ぶときもこの表を使う。
     *
     * @var array<int, int>
     */
    public const AGE_GROUP_FROM_AGE = [
        7  => 2, // 低学年
        10 => 3, // 中学年
        12 => 4, // 高学年
        13 => 5, // 中学生
        16 => 6, // 高校生
        19 => 7, // 大人
    ];

    /**
     * 年齢から年代を返す。
     */
    public static function ageGroupForAge(int $age): int
    {
        $group = 1;
        foreach (self::AGE_GROUP_FROM_AGE as $fromAge => $rank) {
            if ($age < $fromAge) {
                return $group;
            }
            $group = $rank;
        }

        return $group;
    }

    /**
     * 選択肢の表示名を返す。値が無ければ「未設定」、選択肢に無い値ならその値をそのまま示す。
     *
     * @param array<int, string> $options
     */
    public static function label(array $options, int|string|null $value): string
    {
        if ($value === null || $value === '') {
            return '未設定';
        }
        if (!isset($options[(int)$value])) {
            return '定義外の値（' . $value . '）';
        }

        return $options[(int)$value];
    }

    private function __construct() {}
}
