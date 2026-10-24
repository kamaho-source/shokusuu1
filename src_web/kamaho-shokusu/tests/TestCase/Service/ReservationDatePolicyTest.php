<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ReservationDatePolicy;
use Cake\I18n\Date;
use Cake\TestSuite\TestCase;

class ReservationDatePolicyTest extends TestCase
{
    private ReservationDatePolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new ReservationDatePolicy();
    }

    public function testValidateReservationDateRejectsEmptyDate(): void
    {
        $result = $this->policy->validateReservationDate(null);
        $this->assertSame('予約日が指定されていません。', $result);
    }

    public function testValidateReservationDateRejectsInvalidDate(): void
    {
        $result = $this->policy->validateReservationDate('invalid-date');
        $this->assertSame('無効な日付フォーマットです。', $result);
    }

    public function testValidateReservationDateRejectsWithinFourteenDays(): void
    {
        $date = Date::today()->addDays(14)->format('Y-m-d');

        $result = $this->policy->validateReservationDate($date);

        $this->assertIsString($result);
        $this->assertStringContainsString('15日目以降', $result);
    }

    public function testValidateReservationDateAcceptsFromFifteenDays(): void
    {
        $date = Date::today()->addDays(15)->format('Y-m-d');

        $result = $this->policy->validateReservationDate($date);

        $this->assertTrue($result);
    }

    public function testChangeBoundaryDateIsTodayPlusFourteenDays(): void
    {
        $today = Date::today();

        $boundary = $this->policy->changeBoundaryDate($today);

        $this->assertSame($today->addDays(14)->format('Y-m-d'), $boundary->format('Y-m-d'));
    }

    public function testMinimumOrderDateIsTodayPlusFifteenDays(): void
    {
        $today = Date::today();

        $minDate = $this->policy->minimumOrderDate($today);

        $this->assertSame($today->addDays(15)->format('Y-m-d'), $minDate->format('Y-m-d'));
    }

    public function testShouldUseChangeFlagUsesBoundaryOnly(): void
    {
        $today = Date::today();

        $this->assertTrue($this->policy->shouldUseChangeFlag($today->addDays(14), $today));
        $this->assertFalse($this->policy->shouldUseChangeFlag($today->addDays(15), $today));
    }

    public function testIsLastMinuteWindowRequiresTodayOrLater(): void
    {
        $today = Date::today();

        $this->assertTrue($this->policy->isLastMinuteWindow($today, $today));
        $this->assertTrue($this->policy->isLastMinuteWindow($today->addDays(14), $today));
        $this->assertFalse($this->policy->isLastMinuteWindow($today->addDays(15), $today));
        $this->assertFalse($this->policy->isLastMinuteWindow($today->subDays(1), $today));
    }

    public function testJudgeColumnUsesDistanceRule(): void
    {
        $today = Date::today();

        $this->assertSame('i_change_flag', $this->policy->judgeColumn($today->addDays(14), $today));
        $this->assertSame('eat_flag', $this->policy->judgeColumn($today->addDays(15), $today));
    }

    /** 食数として数える値: 境界日以前（過去日を含む）は i_change_flag、NULL なら eat_flag、それより先は eat_flag */
    public function testCountedValue(): void
    {
        $policy = new ReservationDatePolicy();
        $past = \Cake\I18n\Date::today()->subDays(3);
        $d14  = \Cake\I18n\Date::today()->addDays(14);
        $d15  = \Cake\I18n\Date::today()->addDays(15);

        $this->assertSame(1, $policy->countedValue($past, 0, 1), '過去日の直前追加を数える');
        $this->assertSame(0, $policy->countedValue($past, 1, 0), '過去日の直前取消を数えない');
        $this->assertSame(1, $policy->countedValue($d14, 1, null), '境界日以前で i_change_flag が NULL なら eat_flag');
        $this->assertSame(0, $policy->countedValue($d14, 1, 0));
        $this->assertSame(1, $policy->countedValue($d15, 1, 0), '境界日の翌日からは eat_flag');
        $this->assertSame(0, $policy->countedValue($d15->format('Y-m-d'), 0, 1));
    }
}
