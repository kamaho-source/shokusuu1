<?php
declare(strict_types=1);

namespace App\Test\TestCase\Domain\Service;

use App\Domain\Service\MealGridWeekRule;
use Cake\Chronos\Chronos;
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

    /**
     * 旧 ActualMealManagementService::getAdminOldestAllowedMonday の期待値。月末でも翌月へ繰り上がらない（#919）。
     * この 3 日はエクセル食数予約・実食入力のどちらでも同じ
     */
    public function testAdminOldestMonday_doesNotOverflowOnMonthEnd(): void
    {
        $cases = [
            '2026-12-31' => '2026-10-26',
            '2026-04-30' => '2026-02-23', // 繰り上がると 3/2 の週になる
            '2026-11-02' => '2026-08-31',
        ];
        foreach ($cases as $today => $expected) {
            $day = new \DateTimeImmutable($today . ' 00:00:00', new \DateTimeZone('Asia/Tokyo'));
            foreach ([MealGridWeekRule::adminOldestMonday($day), MealGridWeekRule::actualMealAdminOldestMonday($day)] as $monday) {
                $this->assertSame($expected, $monday->format('Y-m-d'), $today);
                $this->assertSame('1', $monday->format('N'), $today);
            }
        }
    }

    /**
     * 実食入力の最古の週は、2026 年のどの日でも旧 getAdminOldestAllowedMonday（今日から 2 か月前の日を含む週の月曜）と同じ。
     * resolve（実食入力）で最古より前を指定したときも、その週に収まる
     */
    public function testActualMealAdminOldestMonday_matchesOldImplementationForEveryDayOf2026(): void
    {
        $tz = new \DateTimeZone('Asia/Tokyo');
        for ($day = new \DateTimeImmutable('2026-01-01 10:00:00', $tz); $day->format('Y') === '2026'; $day = $day->modify('+1 day')) {
            // 旧実装: DateTime::now('Asia/Tokyo')->subMonths(2) → 月曜でなければ 'monday this week'
            $old      = Chronos::instance($day)->subMonths(2);
            $expected = ((int)$old->format('N') === 1 ? $old : $old->modify('monday this week'))->format('Y-m-d');

            $label = $day->format('Y-m-d');
            $this->assertSame($expected, MealGridWeekRule::actualMealAdminOldestMonday($day)->format('Y-m-d'), $label);
            $this->assertSame($expected, MealGridWeekRule::resolve('2000-01-03', true, $day, true)->mondayString(), $label);
            $this->assertFalse(MealGridWeekRule::resolve($expected, true, $day, true)->canGoPrev, $label);
        }
    }

    /** エクセル食数予約の最古の週は今週の月曜から数える（実食入力とは起点が違い、1 週早い日がある） */
    public function testResolve_mealCountGridKeepsThisMondayAsOrigin(): void
    {
        // 2026-05-17（日）: 今週の月曜 05-11 → 03-11 → 03-09。実食入力は 05-17 → 03-17 → 03-16
        $sunday = new \DateTimeImmutable('2026-05-17', new \DateTimeZone('Asia/Tokyo'));
        $this->assertSame('2026-03-09', MealGridWeekRule::resolve('2000-01-03', true, $sunday)->mondayString());
        $this->assertSame('2026-03-16', MealGridWeekRule::resolve('2000-01-03', true, $sunday, true)->mondayString());
    }

    /** 今日から見て、だいたい 2 か月前（±7 日）の月曜日 */
    public function testAdminOldestMonday_isMondayAboutTwoMonthsAgo(): void
    {
        $now    = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo'));
        $result = MealGridWeekRule::adminOldestMonday($now);

        $this->assertSame('1', $result->format('N'));
        $this->assertLessThanOrEqual(7, abs((int)$result->diff($now->modify('-2 months'))->days));
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

    /** 実食入力（forActualMeal）は、管理者以外は先の週を見ていても前の週へ移れない。管理者は今までどおり */
    public function testResolve_forActualMeal_prevOnlyForAdmin(): void
    {
        $this->assertTrue(MealGridWeekRule::resolve('2026-05-18', false, $this->thisMonday)->canGoPrev);
        $this->assertFalse(MealGridWeekRule::resolve('2026-05-18', false, $this->thisMonday, true)->canGoPrev);
        $this->assertTrue(MealGridWeekRule::resolve('2026-05-04', true, $this->thisMonday, true)->canGoPrev);
        $this->assertFalse(MealGridWeekRule::resolve('2026-03-09', true, $this->thisMonday, true)->canGoPrev);
    }

    /**
     * 今日に時刻が付いていても、週の境界は日付だけで決まる。
     * 月曜だけ時刻が残り、管理者以外が翌週を見ると「前の週へ」が false になっていた（エクセル食数予約）
     */
    public function testResolve_timeOfDayDoesNotAffectWeekBoundary(): void
    {
        $tz = new \DateTimeZone('Asia/Tokyo');
        // 月曜 10:00 / 水曜 10:00 / 日曜 23:59（どれも 2026-05-11 の週）
        foreach (['2026-05-11 10:00:00', '2026-05-13 10:00:00', '2026-05-17 23:59:59'] as $now) {
            $today = new \DateTimeImmutable($now, $tz);

            $nextWeek = MealGridWeekRule::resolve('2026-05-18', false, $today);
            $this->assertSame('2026-05-18', $nextWeek->mondayString(), $now);
            $this->assertTrue($nextWeek->canGoPrev, $now . ' 管理者以外でも翌週から今週へ戻れる');
            $this->assertTrue($nextWeek->canGoNext, $now);

            $thisWeek = MealGridWeekRule::resolve(null, false, $today);
            $this->assertSame('2026-05-11', $thisWeek->mondayString(), $now);
            $this->assertFalse($thisWeek->canGoPrev, $now . ' 管理者以外は今週より前へ戻れない');

            $this->assertSame('2026-06-08', MealGridWeekRule::resolve('2026-07-20', false, $today)->mondayString(), $now);
            $this->assertFalse(MealGridWeekRule::resolve('2026-06-08', false, $today)->canGoNext, $now);
            $this->assertTrue(MealGridWeekRule::resolve('2026-06-01', false, $today)->canGoNext, $now);

            $admin = MealGridWeekRule::resolve('2026-03-16', true, $today);
            $this->assertTrue($admin->canGoPrev, $now);
            $this->assertSame('2026-03-09', MealGridWeekRule::resolve('2026-01-05', true, $today)->mondayString(), $now);
            $this->assertFalse(MealGridWeekRule::resolve('2026-03-09', true, $today)->canGoPrev, $now);

            // 実食入力（forActualMeal）: 管理者以外は戻れない、管理者は戻れる
            $this->assertFalse(MealGridWeekRule::resolve('2026-05-18', false, $today, true)->canGoPrev, $now);
            $this->assertTrue(MealGridWeekRule::resolve('2026-05-18', true, $today, true)->canGoPrev, $now);
        }
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
