<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\BulkReservationFormService;
use Cake\TestSuite\TestCase;

/**
 * BulkReservationFormService テスト。
 *
 * buildBulkAddData と buildBulkChangeEditData の日付ウィンドウ・無効化ロジックを検証する。
 */
class BulkReservationFormServiceTest extends TestCase
{
    private BulkReservationFormService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new BulkReservationFormService();
    }

    // ----------------------------------------------------------------
    // buildBulkAddData — DB不要
    // ----------------------------------------------------------------

    public function testBuildBulkAddData_returnsRequiredKeys(): void
    {
        $future = (new \DateTimeImmutable('+30 days'))->format('Y-m-d');
        $result = $this->service->buildBulkAddData($future, null);

        $this->assertArrayHasKey('days', $result);
        $this->assertArrayHasKey('activeWeekDate', $result);
        $this->assertArrayHasKey('weekStarts', $result);
    }

    public function testBuildBulkAddData_daysAreAtLeastFifteenDaysAhead(): void
    {
        $future = (new \DateTimeImmutable('+30 days'))->format('Y-m-d');
        $result = $this->service->buildBulkAddData($future, null);

        $today = new \DateTimeImmutable('today', new \DateTimeZone('Asia/Tokyo'));
        foreach ($result['days'] as $day) {
            $dayDate = new \DateTimeImmutable($day['date'], new \DateTimeZone('Asia/Tokyo'));
            $diff = (int)$today->diff($dayDate)->days;
            $this->assertGreaterThanOrEqual(15, $diff, "day {$day['date']} should be >= 15 days from today");
        }
    }

    public function testBuildBulkAddData_weekStartsHasFourEntries(): void
    {
        $future = (new \DateTimeImmutable('+30 days'))->format('Y-m-d');
        $result = $this->service->buildBulkAddData($future, null);

        $this->assertCount(4, $result['weekStarts']);
    }

    public function testBuildBulkAddData_eachDayHasRequiredKeys(): void
    {
        $future = (new \DateTimeImmutable('+30 days'))->format('Y-m-d');
        $result = $this->service->buildBulkAddData($future, null);

        foreach ($result['days'] as $day) {
            $this->assertArrayHasKey('date', $day);
            $this->assertArrayHasKey('label', $day);
            $this->assertArrayHasKey('is_disabled', $day);
            $this->assertFalse($day['is_disabled']);
        }
    }

    public function testBuildBulkAddData_pastDateProducesNoDays(): void
    {
        // 過去日を選択した週はすべてのdayが15日以内になるので days は空になる
        $past = (new \DateTimeImmutable('-1 week'))->format('Y-m-d');
        $result = $this->service->buildBulkAddData($past, null);

        $this->assertSame([], $result['days']);
    }

    // ----------------------------------------------------------------
    // buildBulkChangeEditData — DB不要
    // ----------------------------------------------------------------

    public function testBuildBulkChangeEditData_returnsRequiredKeys(): void
    {
        $future = (new \DateTimeImmutable('+30 days'))->format('Y-m-d');
        $result = $this->service->buildBulkChangeEditData($future, null);

        $this->assertArrayHasKey('days', $result);
        $this->assertArrayHasKey('activeWeekDate', $result);
        $this->assertArrayHasKey('weekStarts', $result);
    }

    public function testBuildBulkChangeEditData_alwaysReturnSevenDays(): void
    {
        $future = (new \DateTimeImmutable('+30 days'))->format('Y-m-d');
        $result = $this->service->buildBulkChangeEditData($future, null);

        $this->assertCount(7, $result['days']);
    }

    public function testBuildBulkChangeEditData_pastDaysAreDisabled(): void
    {
        // 過去の月曜日を基点に選択
        $pastMonday = (new \DateTimeImmutable('monday last week'))->format('Y-m-d');
        $result = $this->service->buildBulkChangeEditData($pastMonday, null);

        $today = new \DateTimeImmutable('today', new \DateTimeZone('Asia/Tokyo'));
        foreach ($result['days'] as $day) {
            $dayDate = new \DateTimeImmutable($day['date'], new \DateTimeZone('Asia/Tokyo'));
            if ($dayDate < $today) {
                $this->assertTrue($day['is_disabled'], "past day {$day['date']} should be disabled");
            }
        }
    }

    public function testBuildBulkChangeEditData_futureDaysAreNotDisabled(): void
    {
        $future = (new \DateTimeImmutable('+30 days'))->format('Y-m-d');
        $result = $this->service->buildBulkChangeEditData($future, null);

        foreach ($result['days'] as $day) {
            $this->assertFalse($day['is_disabled']);
        }
    }

    public function testBuildBulkChangeEditData_activeWeekDateIsMonday(): void
    {
        $future = (new \DateTimeImmutable('+30 days'))->format('Y-m-d');
        $result = $this->service->buildBulkChangeEditData($future, null);

        $activeWeek = new \DateTimeImmutable($result['activeWeekDate']);
        $this->assertSame('1', $activeWeek->format('N')); // 月曜 = 1
    }
}
