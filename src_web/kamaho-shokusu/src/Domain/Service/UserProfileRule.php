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
     * @param list<int>|null $allowedRoomIds 登録できる部屋（管理者は null で制限なし）
     * @param list<int> $postedRoomIds 送られた登録先の部屋（0 は未選択）
     */
    public static function roomsError(?array $allowedRoomIds, array $postedRoomIds): ?string
    {
        if ($allowedRoomIds === null) {
            return null;
        }
        if ($allowedRoomIds === []) {
            return '所属している部屋がないため、ユーザーを登録できません。管理者に所属部屋の設定を依頼してください。';
        }
        $selected = array_filter($postedRoomIds);
        if ($selected === []) {
            return '登録先の部屋を選択してください。';
        }
        if (array_diff($selected, $allowedRoomIds) !== []) {
            return '自分が所属していない部屋には登録できません。';
        }

        return null;
    }
}
