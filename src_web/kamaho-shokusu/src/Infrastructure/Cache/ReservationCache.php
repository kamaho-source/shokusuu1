<?php
declare(strict_types=1);

namespace App\Infrastructure\Cache;

use Cake\Cache\Cache;
use Cake\I18n\Date;

/**
 * 予約まわりのキャッシュのキーと破棄を一か所にまとめる。
 *
 * - 食数（日付ごと）       : meal_counts:{date}:v2（旧キー meal_counts:{date} も消す）
 * - 当日の食事報告済み印   : today_report:{userId}:{date}
 * - レポートのバージョン   : reservation_version（上げるとレポート系のキャッシュがすべて読み直しになる）
 */
final class ReservationCache
{
    private const CONFIG = 'default';

    private const MEAL_COUNTS_SUFFIX = 'v2';

    public const VERSION_KEY = 'reservation_version';

    public static function mealCountsKey(string $date): string
    {
        return 'meal_counts:' . $date . ':' . self::MEAL_COUNTS_SUFFIX;
    }

    public static function todayReportKey(int $userId, string $date): string
    {
        return sprintf('today_report:%d:%s', $userId, $date);
    }

    /** 日付の食数キャッシュを消す。 */
    public static function forgetMealCounts(string $date): void
    {
        Cache::delete(self::mealCountsKey($date), self::CONFIG);
        Cache::delete('meal_counts:' . $date, self::CONFIG);
    }

    /**
     * 予約が変わった日付のキャッシュを消す。食数を消し、その日が今日なら利用者の「報告済み」印も消す。
     *
     * @param list<int> $userIds 予約が変わった利用者
     */
    public static function forgetDate(string $date, array $userIds = []): void
    {
        if ($date === '') {
            return;
        }
        self::forgetMealCounts($date);
        if ($date !== Date::today('Asia/Tokyo')->format('Y-m-d')) {
            return;
        }
        foreach (array_unique(array_filter(array_map('intval', $userIds))) as $userId) {
            self::forgetTodayReport($userId, $date);
        }
    }

    /** 利用者のその日の「報告済み」印を消す。 */
    public static function forgetTodayReport(int $userId, string $date): void
    {
        Cache::delete(self::todayReportKey($userId, $date), self::CONFIG);
    }

    /** レポートのバージョンを上げる（レポート系のキャッシュをまとめて読み直しにする）。 */
    public static function bumpVersion(): void
    {
        $current = Cache::read(self::VERSION_KEY, self::CONFIG);
        Cache::write(self::VERSION_KEY, (is_int($current) && $current > 0) ? $current + 1 : 2, self::CONFIG);
    }

    /** 今のレポートのバージョン（未設定なら 1）。 */
    public static function version(): int
    {
        $v = Cache::read(self::VERSION_KEY, self::CONFIG);

        return (is_int($v) && $v > 0) ? $v : 1;
    }

    /** 今日の食事報告済みの印を付ける。 */
    public static function markTodayReported(int $userId, string $date): void
    {
        Cache::write(self::todayReportKey($userId, $date), 1, self::CONFIG);
    }
}
