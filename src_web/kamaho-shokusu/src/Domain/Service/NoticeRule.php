<?php
declare(strict_types=1);

namespace App\Domain\Service;

/**
 * お知らせの入力の決まり。
 *
 * - 重要度は 通常(0)・重要(1)、種別は 通常(0)・リリースノート(1)
 * - 掲載開始日・終了日は暦にある YYYY-MM-DD（空は制限なし）。終了日は開始日より前にできない
 * - 種別とモーダル表示を決められるのはシステム管理者だけ。それ以外は、登録なら通常・表示しない、更新なら今の値のまま
 */
final class NoticeRule
{
    public const IMPORTANCE_NORMAL = 0;
    public const IMPORTANCE_HIGH = 1;
    public const TYPE_NORMAL = 0;
    public const TYPE_RELEASE_NOTE = 1;

    /** 入力の問題（利用者に出す文言）。問題が無ければ null。 */
    public static function error(int $importance, int $type, ?string $startDate, ?string $endDate): ?string
    {
        if (!in_array($importance, [self::IMPORTANCE_NORMAL, self::IMPORTANCE_HIGH], true)) {
            return '重要度の値が不正です。';
        }
        if (!in_array($type, [self::TYPE_NORMAL, self::TYPE_RELEASE_NOTE], true)) {
            return '種別の値が不正です。';
        }
        if ($startDate !== null && !DatePeriodRule::isDate($startDate)) {
            return '開始日の形式が不正です。';
        }
        if ($endDate !== null && !DatePeriodRule::isDate($endDate)) {
            return '終了日の形式が不正です。';
        }
        if ($startDate !== null && $endDate !== null && $endDate < $startDate) {
            return '終了日は開始日以降の日付を指定してください。';
        }

        return null;
    }

    /** 保存する種別。システム管理者だけが指定でき、それ以外は今の種別（登録なら通常）のまま。 */
    public static function resolveType(bool $isSystemAdmin, int $requested, ?int $current): int
    {
        return $isSystemAdmin ? $requested : ($current ?? self::TYPE_NORMAL);
    }

    /** 保存するモーダル表示。システム管理者だけが指定でき、それ以外は今の値（登録なら表示しない）のまま。 */
    public static function resolveModal(bool $isSystemAdmin, bool $requested, ?bool $current): bool
    {
        return $isSystemAdmin ? $requested : ($current ?? false);
    }

    private function __construct() {}
}
