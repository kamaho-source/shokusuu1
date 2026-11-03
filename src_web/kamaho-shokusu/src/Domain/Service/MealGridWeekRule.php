<?php
declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\ValueObject\MealGridWeek;
use Cake\Chronos\ChronosDate;

/**
 * エクセル食数予約・実食入力の表示期間の決まり。
 *
 * - 表示は月曜始まりの 28 日（4 週）。実食入力は 7 日（dateRange の日数で指定）
 * - 管理者は 2 か月前の週まで戻れる。それ以外は今週より前に戻れない
 * - 先へ進めるのは今週から 4 週先まで
 */
final class MealGridWeekRule
{
    /** 表示する日数 */
    public const DAYS = 28;

    /** 管理者が遡れる最大月数 */
    private const ADMIN_LOOKBACK_MONTHS = 2;

    /** 先へ進める週数 */
    private const FUTURE_WEEKS = 4;

    /**
     * 指定の月曜日から $days 日分の日付（YYYY-MM-DD）。
     *
     * @return list<string>
     */
    public static function dateRange(string $mondayStr, int $days = self::DAYS): array
    {
        $base  = new \DateTimeImmutable($mondayStr);
        $dates = [];
        for ($i = 0; $i < $days; $i++) {
            $dates[] = $base->modify("+{$i} days")->format('Y-m-d');
        }

        return $dates;
    }

    /**
     * 期間の表示（例: 2025/11/10 〜 2025/12/07 (28日)）。日付が無ければ空文字。
     *
     * @param list<string> $dates
     */
    public static function periodLabel(array $dates): string
    {
        if ($dates === []) {
            return '';
        }
        $start = (new \DateTimeImmutable($dates[0]))->format('Y/m/d');
        $end   = (new \DateTimeImmutable($dates[count($dates) - 1]))->format('Y/m/d');

        return $start . ' 〜 ' . $end . ' (' . count($dates) . '日)';
    }

    /** 管理者が遡れる最古の週の月曜日。 */
    public static function adminOldestMonday(\DateTimeImmutable $today): \DateTimeImmutable
    {
        return self::mondayOf(self::mondayOf($today)->modify('-' . self::ADMIN_LOOKBACK_MONTHS . ' months'));
    }

    /**
     * 指定の週（どの曜日でもよい。読めなければ今週）を、移れる範囲に収めて返す。
     *
     * @param bool $prevOnlyForAdmin true なら管理者以外は前の週へ移れない（実食入力。今週より先を見ていても戻れない）
     */
    public static function resolve(?string $weekParam, bool $isAdmin, \DateTimeImmutable $today, bool $prevOnlyForAdmin = false): MealGridWeek
    {
        $thisMonday   = self::mondayOf($today);
        $oldestMonday = $isAdmin ? self::adminOldestMonday($today) : $thisMonday;
        $futureMonday = $thisMonday->modify('+' . self::FUTURE_WEEKS . ' weeks');

        $weekMonday = $thisMonday;
        if ($weekParam !== null) {
            try {
                $weekMonday = self::mondayOf(new \DateTimeImmutable($weekParam, new \DateTimeZone('Asia/Tokyo')));
            } catch (\Exception) {
                $weekMonday = $thisMonday;
            }
        }

        // 最古の週より前・先の上限より後は、端の週にする（管理者以外の最古は今週）
        if ($weekMonday < $oldestMonday) {
            $weekMonday = $oldestMonday;
        }
        if ($weekMonday > $futureMonday) {
            $weekMonday = $futureMonday;
        }

        $prevMonday = $weekMonday->modify('-7 days');
        $nextMonday = $weekMonday->modify('+7 days');

        return new MealGridWeek(
            $weekMonday,
            $prevMonday,
            $nextMonday,
            ($isAdmin || !$prevOnlyForAdmin) && $prevMonday >= $oldestMonday,
            $nextMonday <= $futureMonday,
        );
    }

    /**
     * 日付ごとの区分。past = 今日より前、last_minute = 今日〜今日+14日（直前編集）、normal = それより先。
     *
     * @param list<string> $dates
     * @return array<string, string>
     */
    public static function dateCategories(array $dates, ChronosDate $today): array
    {
        $border = ReservationDateRule::changeBoundaryDate($today);

        $categories = [];
        foreach ($dates as $d) {
            $date = new ChronosDate($d);
            $categories[$d] = match (true) {
                $date->lessThan($today)          => 'past',
                $date->lessThanOrEquals($border) => 'last_minute',
                default                          => 'normal',
            };
        }

        return $categories;
    }

    /** その週の月曜日の 0:00（時刻は落とす。月曜だけ時刻が残ると、今週の月曜との比較がずれる） */
    private static function mondayOf(\DateTimeImmutable $date): \DateTimeImmutable
    {
        $day = $date->setTime(0, 0);

        return (int)$day->format('N') === 1 ? $day : $day->modify('monday this week');
    }

    private function __construct() {}
}
