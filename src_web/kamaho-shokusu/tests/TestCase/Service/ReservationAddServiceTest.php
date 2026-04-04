<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ReservationAddService;
use App\Service\ReservationDatePolicy;
use Cake\TestSuite\TestCase;

/**
 * ReservationAddService のテスト
 *
 * フォームバリデーションロジックを検証する。DB 不要。
 */
class ReservationAddServiceTest extends TestCase
{
    private ReservationAddService $service;

    public function setUp(): void
    {
        parent::setUp();
        $this->service = new ReservationAddService();
    }

    // ---------------------------------------------------------------------------
    // ensureReservationDate()
    // ---------------------------------------------------------------------------

    public function testEnsureReservationDateSetsDateWhenEmpty(): void
    {
        $data = $this->service->ensureReservationDate([], '2026-05-01');

        $this->assertSame('2026-05-01', $data['d_reservation_date']);
    }

    public function testEnsureReservationDatePreservesExistingDate(): void
    {
        $data = $this->service->ensureReservationDate(['d_reservation_date' => '2026-06-01'], '2026-05-01');

        $this->assertSame('2026-06-01', $data['d_reservation_date']);
    }

    // ---------------------------------------------------------------------------
    // validateLunchBento()
    // ---------------------------------------------------------------------------

    public function testValidateLunchBentoReturnsNullWhenNeitherSelected(): void
    {
        $error = $this->service->validateLunchBento([]);

        $this->assertNull($error);
    }

    public function testValidateLunchBentoReturnsNullWhenOnlyLunchSelected(): void
    {
        $error = $this->service->validateLunchBento(['lunch' => '1']);

        $this->assertNull($error);
    }

    public function testValidateLunchBentoReturnsNullWhenOnlyBentoSelected(): void
    {
        $error = $this->service->validateLunchBento(['bento' => '1']);

        $this->assertNull($error);
    }

    public function testValidateLunchBentoReturnsErrorWhenBothSelected(): void
    {
        $error = $this->service->validateLunchBento(['lunch' => '1', 'bento' => '1']);

        $this->assertIsString($error);
        $this->assertStringContainsString('昼食と弁当', $error);
    }

    // ---------------------------------------------------------------------------
    // validateDate()
    // ---------------------------------------------------------------------------

    public function testValidateDateDelegatesToPolicy(): void
    {
        $mock = $this->getMockBuilder(ReservationDatePolicy::class)
            ->onlyMethods(['validateReservationDate'])
            ->getMock();
        $mock->method('validateReservationDate')->willReturn(true);

        $result = $this->service->validateDate('2026-05-01', $mock);

        $this->assertTrue($result);
    }

    public function testValidateDateReturnsErrorMessageFromPolicy(): void
    {
        $mock = $this->getMockBuilder(ReservationDatePolicy::class)
            ->onlyMethods(['validateReservationDate'])
            ->getMock();
        $mock->method('validateReservationDate')->willReturn('無効な日付です。');

        $result = $this->service->validateDate('2026-01-01', $mock);

        $this->assertSame('無効な日付です。', $result);
    }
}
