<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ReservationCalendarService;
use Cake\I18n\Date;
use Cake\TestSuite\TestCase;

/**
 * ReservationCalendarService テスト。
 *
 * buildMyReservationDates・buildCalendarEvents の予約判定ロジックを検証する。
 */
class ReservationCalendarServiceTest extends TestCase
{
    private ReservationCalendarService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ReservationCalendarService();
    }

    // ----------------------------------------------------------------
    // buildMyReservationDates — 予約日判定
    // ----------------------------------------------------------------

    /** @test */
    public function testBuildMyReservationDates_emptyDetails_returnsEmpty(): void
    {
        $result = $this->service->buildMyReservationDates([]);

        $this->assertSame([], $result);
    }

    /** @test */
    public function testBuildMyReservationDates_eatFlagOne_includesDate(): void
    {
        $details = [
            '2099-01-01' => ['breakfast' => 1, 'lunch' => null, 'dinner' => null, 'bento' => null],
        ];

        $result = $this->service->buildMyReservationDates($details);

        $this->assertSame(['2099-01-01'], $result);
    }

    /** @test */
    public function testBuildMyReservationDates_eatFlagZero_includesDate(): void
    {
        // 「食べない（0）」も予約レコードとして扱う
        $details = [
            '2099-01-02' => ['breakfast' => 0, 'lunch' => null, 'dinner' => null, 'bento' => null],
        ];

        $result = $this->service->buildMyReservationDates($details);

        $this->assertSame(['2099-01-02'], $result);
    }

    /** @test */
    public function testBuildMyReservationDates_allNull_excludesDate(): void
    {
        // 4種全てnull = 予約レコードなし → 未予約
        $details = [
            '2099-01-03' => ['breakfast' => null, 'lunch' => null, 'dinner' => null, 'bento' => null],
        ];

        $result = $this->service->buildMyReservationDates($details);

        $this->assertSame([], $result);
    }

    /** @test */
    public function testBuildMyReservationDates_lunchOnlyReserved_includesDate(): void
    {
        // 昼食のみ予約でも予約済みと判定される
        $details = [
            '2099-01-04' => ['breakfast' => null, 'lunch' => 1, 'dinner' => null, 'bento' => null],
        ];

        $result = $this->service->buildMyReservationDates($details);

        $this->assertSame(['2099-01-04'], $result);
    }

    /** @test */
    public function testBuildMyReservationDates_mixedDates_returnsSortedDates(): void
    {
        $details = [
            '2099-03-01' => ['breakfast' => 1, 'lunch' => null, 'dinner' => null, 'bento' => null],
            '2099-01-01' => ['breakfast' => null, 'lunch' => null, 'dinner' => null, 'bento' => null],
            '2099-02-01' => ['breakfast' => 0, 'lunch' => null, 'dinner' => null, 'bento' => null],
        ];

        $result = $this->service->buildMyReservationDates($details);

        $this->assertSame(['2099-02-01', '2099-03-01'], $result);
    }

    // ----------------------------------------------------------------
    // getPrimaryRoomId — DB不要
    // ----------------------------------------------------------------

    /** @test */
    public function testGetPrimaryRoomId_emptyArray_returnsNull(): void
    {
        $this->assertNull($this->service->getPrimaryRoomId([]));
    }

    /** @test */
    public function testGetPrimaryRoomId_returnsFirstElement(): void
    {
        $this->assertSame(42, $this->service->getPrimaryRoomId([42, 10, 5]));
    }

    // ----------------------------------------------------------------
    // buildCalendarEvents — 未予約イベント生成
    // ----------------------------------------------------------------

    /** @test */
    public function testBuildCalendarEvents_noReservations_generatesUnreservedEvents(): void
    {
        $startDate = new Date('2099-01-01');
        $endDate   = new Date('2099-01-04');

        $events = $this->service->buildCalendarEvents([], [], $startDate, $endDate);

        $titles = array_column($events, 'title');
        $this->assertContains('未予約', $titles);
    }

    /** @test */
    public function testBuildCalendarEvents_withEatFlagZero_notUnreserved(): void
    {
        // 「食べない（0）」が設定されている日は「未予約」にならない
        $startDate = new Date('2099-01-01');
        $endDate   = new Date('2099-01-02');
        $details   = [
            '2099-01-01' => ['breakfast' => 0, 'lunch' => null, 'dinner' => null, 'bento' => null],
        ];

        $events = $this->service->buildCalendarEvents([], $details, $startDate, $endDate);

        $unreservedDates = array_column(
            array_filter($events, fn ($e) => $e['title'] === '未予約'),
            'start'
        );
        $this->assertNotContains('2099-01-01', $unreservedDates);
    }
}
