<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\DashboardService;
use Cake\TestSuite\TestCase;

/**
 * DashboardService のテスト
 *
 * buildHomeContext() が返す日付ロジックを検証する。
 * DB は使わないため純粋な単体テスト。
 */
class DashboardServiceTest extends TestCase
{
    private DashboardService $service;

    public function setUp(): void
    {
        parent::setUp();
        $this->service = new DashboardService();
    }

    // ---------------------------------------------------------------------------
    // buildHomeContext()
    // ---------------------------------------------------------------------------

    public function testBuildHomeContextReturnsRequiredKeys(): void
    {
        $user = null;
        $context = $this->service->buildHomeContext($user);

        $this->assertArrayHasKey('todayLabel', $context);
        $this->assertArrayHasKey('todayParam', $context);
        $this->assertArrayHasKey('thisWeekMonday', $context);
        $this->assertArrayHasKey('nextWeekMonday', $context);
        $this->assertArrayHasKey('nextNextWeekMonday', $context);
        $this->assertArrayHasKey('firstNormalWeekMonday', $context);
        $this->assertArrayHasKey('secondNormalWeekMonday', $context);
        $this->assertArrayHasKey('thirdNormalWeekMonday', $context);
        $this->assertArrayHasKey('fmtWeekRange', $context);
    }

    public function testTodayParamIsIsoDate(): void
    {
        $context = $this->service->buildHomeContext(null);

        $this->assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2}$/', $context['todayParam']);
    }

    public function testTodayLabelContainsJapaneseWeekday(): void
    {
        $context = $this->service->buildHomeContext(null);

        // 例: 「2026年4月5日(日)」のように曜日が含まれる（マルチバイト文字クラスに u フラグ必須）
        $this->assertMatchesRegularExpression(
            '/\d{4}年\d{1,2}月\d{1,2}日\([日月火水木金土]\)/u',
            $context['todayLabel']
        );
    }

    public function testThisWeekMondayIsMonday(): void
    {
        $context = $this->service->buildHomeContext(null);
        /** @var \DateTimeImmutable $monday */
        $monday = $context['thisWeekMonday'];

        // ISO 曜日: 1 = 月曜日
        $this->assertSame('1', $monday->format('N'));
    }

    public function testNextWeekMondayIsSevenDaysAfterThisWeek(): void
    {
        $context = $this->service->buildHomeContext(null);

        $diff = $context['thisWeekMonday']->diff($context['nextWeekMonday']);
        $this->assertSame(7, $diff->days);
    }

    public function testNextNextWeekMondayIsFourteenDaysAfterThisWeek(): void
    {
        $context = $this->service->buildHomeContext(null);

        $diff = $context['thisWeekMonday']->diff($context['nextNextWeekMonday']);
        $this->assertSame(14, $diff->days);
    }

    public function testFirstNormalWeekMondayIsAtLeast15DaysFromNow(): void
    {
        $context = $this->service->buildHomeContext(null);

        $today = new \DateTimeImmutable('today', new \DateTimeZone('Asia/Tokyo'));
        $minDate = $today->modify('+15 days');
        /** @var \DateTimeImmutable $firstNormal */
        $firstNormal = $context['firstNormalWeekMonday'];

        // firstNormalWeekMonday は minDate 以上でなければならない
        $this->assertGreaterThanOrEqual(
            $minDate->format('Y-m-d'),
            $firstNormal->format('Y-m-d'),
            'firstNormalWeekMonday は今日から15日後以降でなければならない'
        );
        $this->assertSame('1', $firstNormal->format('N'), 'firstNormalWeekMonday は月曜日でなければならない');
    }

    public function testSecondNormalWeekMondayIsSevenDaysAfterFirst(): void
    {
        $context = $this->service->buildHomeContext(null);

        $diff = $context['firstNormalWeekMonday']->diff($context['secondNormalWeekMonday']);
        $this->assertSame(7, $diff->days);
    }

    public function testThirdNormalWeekMondayIsFourteenDaysAfterFirst(): void
    {
        $context = $this->service->buildHomeContext(null);

        $diff = $context['firstNormalWeekMonday']->diff($context['thirdNormalWeekMonday']);
        $this->assertSame(14, $diff->days);
    }

    public function testFmtWeekRangeReturnsMondayToFridayString(): void
    {
        $context = $this->service->buildHomeContext(null);
        $fmt = $context['fmtWeekRange'];

        // 既知の月曜日 (2026-02-23) で検証
        $monday = new \DateTimeImmutable('2026-02-23', new \DateTimeZone('Asia/Tokyo'));
        $result = $fmt($monday);

        // 「2/23(月) 〜 2/27(金)」形式
        $this->assertStringContainsString('2/23', $result);
        $this->assertStringContainsString('2/27', $result);
        $this->assertStringContainsString('月', $result);
        $this->assertStringContainsString('金', $result);
    }
}
