<?php
declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\ValueObject\UserProfileOptions;

/**
 * 利用者のプロフィール（役職・性別・年代・年齢・職員ID）と、登録先の部屋のルール。
 */
final class UserProfileRule
{
    /** 役職が児童の値（i_user_level）。 */
    public const CHILD_LEVEL = 1;

    /**
     * 職員IDとして保存できるか。列は数値（int）なので、数字以外は黙って空で保存され、
     * 大きすぎる値は SQL エラーになる。9桁以内の数字に限る（新規登録・編集で共通）。
     */
    public static function isValidStaffId(string $staffId): bool
    {
        return ctype_digit($staffId) && strlen($staffId) <= 9;
    }

    /**
     * 新規登録の入力の誤り。問題なければ null。確かめる順番は画面の表示と同じ。
     *
     * @param bool $childOnly 管理者以外（職員・ブロック長）の登録で、児童しか登録できないか
     * @param array<array-key, mixed> $data 送られた値（role・age・age_group・i_user_gender・staff_id）
     * @param int $level 登録する役職（児童しか登録できない場合は児童）
     */
    public static function creationError(bool $childOnly, array $data, int $level): ?string
    {
        if ($childOnly && (string)($data['role'] ?? '') !== (string)self::CHILD_LEVEL) {
            return '管理者以外は児童のみ登録できます。';
        }
        $age = (int)($data['age'] ?? 0);
        if ($age < UserProfileOptions::MIN_AGE || $age > UserProfileOptions::MAX_AGE
            || !isset(UserProfileOptions::AGE_GROUPS[(int)($data['age_group'] ?? 0)])
            || !isset(UserProfileOptions::GENDERS[(int)($data['i_user_gender'] ?? 0)])
        ) {
            return '性別・年代・年齢を選択してください。';
        }
        if ($level === UserProfileOptions::STAFF_LEVEL && !self::isValidStaffId(trim((string)($data['staff_id'] ?? '')))) {
            return '職員IDは9桁以内の数字で入力してください。';
        }

        return null;
    }

    /**
     * 登録先の部屋の誤り。問題なければ null。
     *
     * 管理者・システム管理者も、自分が所属している部屋にだけ登録できる（#821）。部屋の選択が必須なのは管理者以外。
     *
     * @param list<int> $allowedRoomIds 登録できる部屋（操作する人の所属部屋）
     * @param list<int> $postedRoomIds 送られた登録先の部屋（0 は未選択）
     * @param bool $required 部屋の選択が必須か（管理者以外）
     */
    public static function roomsError(array $allowedRoomIds, array $postedRoomIds, bool $required): ?string
    {
        $selected = array_filter($postedRoomIds);
        if ($required && $allowedRoomIds === []) {
            return '所属している部屋がないため、ユーザーを登録できません。管理者に所属部屋の設定を依頼してください。';
        }
        if ($required && $selected === []) {
            return '登録先の部屋を選択してください。';
        }
        if (array_diff($selected, $allowedRoomIds) !== []) {
            return '自分が所属していない部屋には登録できません。';
        }

        return null;
    }

    /**
     * 編集画面の性別・年代・役職・職員IDを確かめる（管理者が保存するとき）。
     * 「未設定」（空）で保存したときは、その項目を変えない（既存ユーザーには未入力の人がいるため）。
     * 役職が職員以外なら職員IDは持たない（新規登録と同じ扱い）。
     * 職員IDの入力を求めるのは、役職を職員に変えたときだけ（職員IDの無い既存の職員も保存できるように）。
     *
     * @param array<string, mixed> $data
     * @param int|null $currentLevel 保存前の役職
     * @return array{0: array<string, mixed>, 1: string|null} [整えたデータ, エラー文]
     */
    public static function normalizeForEdit(array $data, ?int $currentLevel): array
    {
        foreach ([
            'i_user_gender' => UserProfileOptions::GENDERS,
            'i_user_rank'   => UserProfileOptions::AGE_GROUPS,
            'i_user_level'  => UserProfileOptions::USER_LEVELS,
        ] as $field => $options) {
            if (!array_key_exists($field, $data)) {
                continue;
            }
            if ($data[$field] === '') {
                // 「未設定」のまま保存したときは変更しない（年代は空にできないテーブルの検証があるため、消すことはしない）
                unset($data[$field]);
                continue;
            }
            if (!is_scalar($data[$field]) || !ctype_digit((string)$data[$field]) || !isset($options[(int)$data[$field]])) {
                return [$data, '性別・年代・役職の選択が正しくありません。'];
            }
        }

        if (!array_key_exists('i_user_level', $data)) {
            return [$data, null];
        }
        if ((int)$data['i_user_level'] !== UserProfileOptions::STAFF_LEVEL) {
            $data['i_id_staff'] = null;
            return [$data, null];
        }
        $staffId = trim((string)($data['i_id_staff'] ?? ''));
        if ($staffId === '') {
            if ($currentLevel !== UserProfileOptions::STAFF_LEVEL) {
                return [$data, '役職が職員のときは職員IDを入力してください。'];
            }
            return [$data, null];
        }
        if (!self::isValidStaffId($staffId)) {
            return [$data, '職員IDは9桁以内の数字で入力してください。'];
        }

        return [$data, null];
    }

    /**
     * 本人（職員）が自分の職員IDを保存するときの確認。空なら変えない。
     *
     * @param array<string, mixed> $data
     * @return array{0: array<string, mixed>, 1: string|null} [整えたデータ, エラー文]
     */
    public static function normalizeOwnStaffId(array $data): array
    {
        $staffId = trim((string)($data['i_id_staff'] ?? ''));
        if ($staffId === '') {
            unset($data['i_id_staff']);
            return [$data, null];
        }
        if (!self::isValidStaffId($staffId)) {
            return [$data, '職員IDは9桁以内の数字で入力してください。'];
        }

        return [$data, null];
    }
}
