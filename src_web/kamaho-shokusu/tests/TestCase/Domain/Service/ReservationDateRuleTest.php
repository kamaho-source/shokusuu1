<?php
declare(strict_types=1);

namespace App\Test\TestCase\Domain\Service;

use App\Domain\Service\ReservationDateRule;
use Cake\Chronos\ChronosDate;
use PHPUnit\Framework\TestCase;

/**
 * 予約の日付ルール（今日を引数で渡す純粋な計算）のテスト。
 */
class ReservationDateRuleTest extends TestCase
{
    private static function d(string $ymd): ChronosDate
    {
        return new ChronosDate($ymd);
    }

    public function testBoundaryAndMinimumOrderDate_crossMonthAndYear(): void
    {
        $this->assertSame('2026-11-14', ReservationDateRule::changeBoundaryDate(self::d('2026-10-31'))->format('Y-m-d'));
        $this->assertSame('2027-01-15', ReservationDateRule::minimumOrderDate(self::d('2026-12-31'))->format('Y-m-d'));
        $this->assertSame('2028-03-14', ReservationDateRule::minimumOrderDate(self::d('2028-02-28'))->format('Y-m-d'));
    }

    public function testUsesChangeFlag_untilBoundaryIncludingPast(): void
    {
        $today = self::d('2026-11-01');
        $this->assertTrue(ReservationDateRule::usesChangeFlag(self::d('2026-10-01'), $today), '過去日も境界日以前');
        $this->assertTrue(ReservationDateRule::usesChangeFlag(self::d('2026-11-15'), $today), '境界日（今日+14日）');
        $this->assertFalse(ReservationDateRule::usesChangeFlag(self::d('2026-11-16'), $today), '今日+15日');
    }

    public function testIsLastMinuteWindow_excludesPast(): void
    {
        $today = self::d('2026-11-01');
        $this->assertFalse(ReservationDateRule::isLastMinuteWindow(self::d('2026-10-31'), $today));
        $this->assertTrue(ReservationDateRule::isLastMinuteWindow($today, $today));
        $this->assertTrue(ReservationDateRule::isLastMinuteWindow(self::d('2026-11-15'), $today));
        $this->assertFalse(ReservationDateRule::isLastMinuteWindow(self::d('2026-11-16'), $today));
    }

    public function testIsPast(): void
    {
        $today = self::d('2026-11-01');
        $this->assertTrue(ReservationDateRule::isPast(self::d('2026-10-31'), $today));
        $this->assertFalse(ReservationDateRule::isPast($today, $today));
    }

    public function testCountedValue_switchesColumnAtBoundary(): void
    {
        $today = self::d('2026-11-01');
        // 境界日以前は i_change_flag
        $this->assertSame(0, ReservationDateRule::countedValue(self::d('2026-11-15'), 1, 0, $today));
        $this->assertSame(1, ReservationDateRule::countedValue(self::d('2026-10-20'), 0, 1, $today));
        // i_change_flag が null なら eat_flag
        $this->assertSame(1, ReservationDateRule::countedValue(self::d('2026-11-15'), 1, null, $today));
        // 境界日より先は eat_flag
        $this->assertSame(1, ReservationDateRule::countedValue(self::d('2026-11-16'), 1, 0, $today));
        $this->assertSame(0, ReservationDateRule::countedValue(self::d('2026-11-16'), null, 1, $today));
    }

    public function testNormalOrderError(): void
    {
        $today = self::d('2026-11-01');
        $this->assertNull(ReservationDateRule::normalOrderError(self::d('2026-11-16'), $today));
        $this->assertSame(
            '通常発注は「きょうから15日目以降」のみ登録できます（2026-11-16 以降）。',
            ReservationDateRule::normalOrderError(self::d('2026-11-15'), $today)
        );
    }
}
