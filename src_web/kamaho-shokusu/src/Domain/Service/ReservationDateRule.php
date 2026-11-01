<?php
declare(strict_types=1);

namespace App\Domain\Service;

use Cake\Chronos\ChronosDate;

/**
 * 食数予約の日付ルール（今日を引数で受け取る純粋な計算）。
 *
 * - 通常予約: 今日から15日後以降の日付のみ登録できる。
 * - 直前編集: 今日から14日後（境界日）以前の日付は i_change_flag を、それより先は eat_flag を使う。
 *   過去日も境界日以前に含まれるため i_change_flag を見る（過ぎた日の直前の追加・取消を見落とさない）。
 *
 * 「今日」は呼び出し側が渡す（Domain は時計を持たない）。
 * 日付型は CakePHP 本体ではなく cakephp/chronos の ChronosDate。Cake\I18n\Date も渡せ、戻り値は渡した型のまま。
 */
final class ReservationDateRule
{
    /** 直前編集ウィンドウの日数（今日からこの日数後までが直前編集）。 */
    public const CHANGE_WINDOW_DAYS = 14;

    /** 通常予約の最短リードタイム（今日からこの日数後から通常予約できる）。 */
    public const NORMAL_ORDER_MIN_DAYS = 15;

    /** 直前編集ウィンドウの最終日（今日+14日）。 */
    public static function changeBoundaryDate(ChronosDate $today): ChronosDate
    {
        return $today->addDays(self::CHANGE_WINDOW_DAYS);
    }

    /** 通常予約を受け付ける最初の日（今日+15日）。 */
    public static function minimumOrderDate(ChronosDate $today): ChronosDate
    {
        return $today->addDays(self::NORMAL_ORDER_MIN_DAYS);
    }

    /** 予約日が i_change_flag を使うべきか（境界日以前。過去日を含む）。 */
    public static function usesChangeFlag(ChronosDate $targetDate, ChronosDate $today): bool
    {
        return $targetDate <= self::changeBoundaryDate($today);
    }

    /** 予約日が直前編集ウィンドウ内か（今日以降かつ境界日以前。過去日は含まない）。 */
    public static function isLastMinuteWindow(ChronosDate $targetDate, ChronosDate $today): bool
    {
        return $targetDate >= $today && $targetDate <= self::changeBoundaryDate($today);
    }

    /** 予約日が今日より前か。 */
    public static function isPast(ChronosDate $targetDate, ChronosDate $today): bool
    {
        return $targetDate < $today;
    }

    /**
     * 食数として数える値（1=食べる）。食数の報告・カレンダー・承認・二重予約の判定で共通の基準。
     * 境界日以前は i_change_flag（null なら eat_flag）、それより先は eat_flag。
     */
    public static function countedValue(ChronosDate $targetDate, ?int $eatFlag, ?int $changeFlag, ChronosDate $today): int
    {
        if ($changeFlag !== null && self::usesChangeFlag($targetDate, $today)) {
            return $changeFlag;
        }

        return $eatFlag ?? 0;
    }

    /**
     * 通常予約として登録できない理由。登録できるなら null。
     */
    public static function normalOrderError(ChronosDate $targetDate, ChronosDate $today): ?string
    {
        $minDate = self::minimumOrderDate($today);
        if ($targetDate < $minDate) {
            return sprintf('通常発注は「きょうから15日目以降」のみ登録できます（%s 以降）。', $minDate->format('Y-m-d'));
        }

        return null;
    }
}
