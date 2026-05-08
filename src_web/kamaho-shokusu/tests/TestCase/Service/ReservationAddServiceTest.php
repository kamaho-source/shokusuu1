<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ReservationAddService;
use App\Service\ReservationDatePolicy;
use Cake\TestSuite\TestCase;

class ReservationAddServiceTest extends TestCase
{
    private ReservationAddService $service;

    public function setUp(): void
    {
        parent::setUp();
        $this->service = new ReservationAddService();
    }

    // ------------------------------------------------------------------
    // ensureReservationDate
    // ------------------------------------------------------------------

    public function testEnsureReservationDateUsesProvidedDateWhenEmpty(): void
    {
        $data = $this->service->ensureReservationDate([], '2025-08-01');

        $this->assertSame('2025-08-01', $data['d_reservation_date']);
    }

    public function testEnsureReservationDateKeepsExistingDate(): void
    {
        $data = $this->service->ensureReservationDate(
            ['d_reservation_date' => '2025-07-01'],
            '2025-08-01'
        );

        $this->assertSame('2025-07-01', $data['d_reservation_date']);
    }

    // ------------------------------------------------------------------
    // validateLunchBento
    // ------------------------------------------------------------------

    public function testValidateLunchBentoReturnsNullWhenOnlyLunch(): void
    {
        $this->assertNull($this->service->validateLunchBento(['lunch' => '1']));
    }

    public function testValidateLunchBentoReturnsNullWhenOnlyBento(): void
    {
        $this->assertNull($this->service->validateLunchBento(['bento' => '1']));
    }

    public function testValidateLunchBentoReturnsNullWhenNeither(): void
    {
        $this->assertNull($this->service->validateLunchBento([]));
    }

    public function testValidateLunchBentoReturnsErrorWhenBothSelected(): void
    {
        $error = $this->service->validateLunchBento(['lunch' => '1', 'bento' => '1']);

        $this->assertIsString($error);
        $this->assertStringContainsString('同時に予約できません', $error);
    }

    // ------------------------------------------------------------------
    // validateDate
    // ------------------------------------------------------------------

    public function testValidateDateReturnsTrueForFutureDate(): void
    {
        $futureDate = date('Y-m-d', strtotime('+20 days'));
        $policy = new ReservationDatePolicy();

        $result = $this->service->validateDate($futureDate, $policy);

        $this->assertTrue($result);
    }

    public function testValidateDateReturnsErrorForPastDate(): void
    {
        $pastDate = date('Y-m-d', strtotime('-10 days'));
        $policy = new ReservationDatePolicy();

        $result = $this->service->validateDate($pastDate, $policy);

        $this->assertIsString($result);
    }
}
