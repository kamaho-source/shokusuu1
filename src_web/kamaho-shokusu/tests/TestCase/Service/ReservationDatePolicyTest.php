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
}
