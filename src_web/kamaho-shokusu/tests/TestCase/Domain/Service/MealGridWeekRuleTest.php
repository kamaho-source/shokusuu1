<?php
declare(strict_types=1);

namespace App\Test\TestCase\Domain\Service;

use App\Domain\Service\MealGridWeekRule;
use Cake\Chronos\ChronosDate;
use PHPUnit\Framework\TestCase;

/**
 * エクセル食数予約の表示期間の決まり（MealGridWeekRule）。旧 MealCountGridServiceTest の日付・週の部分を引き継ぐ。
 */
class MealGridWeekRuleTest extends TestCase
{
    /** テスト用の固定月曜日（2026-05-11 は月曜） */
    private \DateTimeImmutable $thisMonday;

    protected function setUp(): void
    {
        parent::setUp();
        $this->thisMonday = new \DateTimeImmutable('2026-05-11', new \DateTimeZone('Asia/Tokyo'));
    }

    // ---- dateRange ----

    public function testDateRange_defaultsToTwentyEightConsecutiveDaysEndingOnSunday(): void
    {
        $dates = MealGridWeekRule::dateRange('2026-05-11');

        $this->assertCount(28, $dates);
        $this->assertSame('2026-05-11', $dates[0]);
        $this->assertSame('2026-05-12', $dates[1]);
        $this->assertSame('2026-06-07', $dates[27]);
        $this->assertSame('7', (new \DateTimeImmutable($dates[27]))->format('N'));
    }

    public function testDateRange_canReturnSevenDays(): void
    {
        $this->assertSame(
            ['2026-05-11', '2026-05-12', '2026-05-13', '2026-05-14', '2026-05-15', '2026-05-16', '2026-05-17'],
            MealGridWeekRule::dateRange('2026-05-11', 7)
        );
    }

    // ---- periodLabel ----

    public function testPeriodLabel_format(): void
    {
        $this->assertSame('2026/05/11 〜 2026/06/07 (28日)', MealGridWeekRule::periodLabel(MealGridWeekRule::dateRange('2026-05-11')));
    }

    public function testPeriodLabel_emptyDates_returnsEmpty(): void
    {
        $this->assertSame('', MealGridWeekRule::periodLabel([]));
    }

    // ---- adminOldestMonday ----

    public function testAdminOldestMonday_isMondayTwoMonthsBack(): void
    {
        // 2026-05-11 の 2 か月前は 2026-03-11（水）→ その週の月曜 03-09
        $this->assertSame('2026-03-09', MealGridWeekRule::adminOldestMonday($this->thisMonday)->format('Y-m-d'));
        // 今日が週の途中でも、今週の月曜から数える（日曜 05-17 → 05-11 → 03-11 → 03-09。05-17 から直接だと 03-16 になる）
        $this->assertSame('2026-03-09', MealGridWeekRule::adminOldestMonday($this->thisMonday->modify('+6 days'))->format('Y-m-d'));
    }

    /** 2 か月前の日が週の途中なら、その週の月曜まで戻す（年をまたいでも同じ） */
    public function testAdminOldestMonday_variousWeekdays(): void
    {
        // 06-01 → 04-01（水）→ 03-30、09-07 → 07-07（火）→ 07-06、2027-03-08 → 01-08（金）→ 01-04、2027-02-15 → 2026-12-15（火）→ 12-14
        $this->assertSame('2026-03-30', MealGridWeekRule::adminOldestMonday(new \DateTimeImmutable('2026-06-01'))->format('Y-m-d'));
        $this->assertSame('2026-07-06', MealGridWeekRule::adminOldestMonday(new \DateTimeImmutable('2026-09-07'))->format('Y-m-d'));
        $this->assertSame('2027-01-04', MealGridWeekRule::adminOldestMonday(new \DateTimeImmutable('2027-03-08'))->format('Y-m-d'));
        $this->assertSame('2026-12-14', MealGridWeekRule::adminOldestMonday(new \DateTimeImmutable('2027-02-15'))->format('Y-m-d'));
    }

    // ---- resolve ----

    public function testResolve_noParam_returnsCurrentWeekWithSurroundingWeeks(): void
    {
        $week = MealGridWeekRule::resolve(null, false, $this->thisMonday);

        $this->assertSame('2026-05-11', $week->mondayString());
        $this->assertSame('2026-05-04', $week->prevMonday->format('Y-m-d'));
        $this->assertSame('2026-05-18', $week->nextMonday->format('Y-m-d'));
        $this->assertFalse($week->canGoPrev, '管理者以外は今週より前に戻れない');
        $this->assertTrue($week->canGoNext);
    }

    public function testResolve_normalizesWednesdayToMonday(): void
    {
        $this->assertSame('2026-05-18', MealGridWeekRule::resolve('2026-05-20', false, $this->thisMonday)->mondayString());
    }

    public function testResolve_todayMidWeek_usesThatWeeksMonday(): void
    {
        $this->assertSame('2026-05-11', MealGridWeekRule::resolve(null, false, $this->thisMonday->modify('+3 days'))->mondayString());
    }

    public function testResolve_nonAdminCannotGoBeforeCurrentWeek(): void
    {
        $this->assertSame('2026-05-11', MealGridWeekRule::resolve('2026-05-04', false, $this->thisMonday)->mondayString());
    }

    public function testResolve_adminCanGoBackToOldestButNotBeyond(): void
    {
        $atOldest = MealGridWeekRule::resolve('2026-03-09', true, $this->thisMonday);
        $this->assertSame('2026-03-09', $atOldest->mondayString());
        $this->assertFalse($atOldest->canGoPrev);

        $this->assertSame('2026-03-09', MealGridWeekRule::resolve('2026-01-05', true, $this->thisMonday)->mondayString());

        $this->assertTrue(MealGridWeekRule::resolve('2026-03-16', true, $this->thisMonday)->canGoPrev, '最古の週の1週後からは最古の週へ戻れる');

        $lastWeek = MealGridWeekRule::resolve('2026-05-04', true, $this->thisMonday);
        $this->assertSame('2026-05-04', $lastWeek->mondayString());
        $this->assertTrue($lastWeek->canGoPrev);
    }

    public function testResolve_clampsFarFutureToFourWeeks(): void
    {
        $week = MealGridWeekRule::resolve('2026-07-20', false, $this->thisMonday);

        $this->assertSame('2026-06-08', $week->mondayString());
        $this->assertFalse($week->canGoNext);
        $this->assertTrue(MealGridWeekRule::resolve('2026-06-01', false, $this->thisMonday)->canGoNext);
    }

    public function testResolve_invalidString_fallsBackToCurrentWeek(): void
    {
        $this->assertSame('2026-05-11', MealGridWeekRule::resolve('not-a-date', true, $this->thisMonday)->mondayString());
    }

    // ---- dateCategories ----

    /** 今日より前は past、今日〜今日+14日は last_minute、それより先は normal */
    public function testDateCategories_boundaries(): void
    {
        $dates = ['2026-05-12', '2026-05-13', '2026-05-27', '2026-05-28'];

        $this->assertSame(
            ['2026-05-12' => 'past', '2026-05-13' => 'last_minute', '2026-05-27' => 'last_minute', '2026-05-28' => 'normal'],
            MealGridWeekRule::dateCategories($dates, new ChronosDate('2026-05-13'))
        );
    }
}
