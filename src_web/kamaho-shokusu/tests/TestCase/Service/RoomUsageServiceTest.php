<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\RoomUsageService;
use Cake\TestSuite\TestCase;

class RoomUsageServiceTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TIndividualReservationInfo',
    ];

    private RoomUsageService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RoomUsageService();
    }

    // ----------------------------------------------------------------
    // getRoomUsage — DB使用
    // ----------------------------------------------------------------

    public function testGetRoomUsage_returnsArray(): void
    {
        $result = $this->service->getRoomUsage();
        $this->assertIsArray($result);
    }

    public function testGetRoomUsage_eachEntryHasRequiredKeys(): void
    {
        $result = $this->service->getRoomUsage();

        foreach ($result as $entry) {
            $this->assertArrayHasKey('room_id', $entry);
            $this->assertArrayHasKey('room_name', $entry);
            $this->assertArrayHasKey('user_count', $entry);
            $this->assertArrayHasKey('capacity', $entry);
            $this->assertArrayHasKey('eat_count', $entry);
            $this->assertArrayHasKey('usage_rate', $entry);
            $this->assertArrayHasKey('staff', $entry);
        }
    }

    public function testGetRoomUsage_withDateRange_returnsArray(): void
    {
        $from = date('Y-m-01');
        $to   = date('Y-m-d');

        $result = $this->service->getRoomUsage($from, $to);

        $this->assertIsArray($result);
    }

    public function testGetRoomUsage_withMealType_returnsArray(): void
    {
        $result = $this->service->getRoomUsage(null, null, 1);
        $this->assertIsArray($result);
    }

    public function testGetRoomUsage_usageRateIsFloat(): void
    {
        $result = $this->service->getRoomUsage();

        foreach ($result as $entry) {
            $this->assertIsFloat($entry['usage_rate']);
        }
    }

    public function testGetRoomUsage_usageRateBetweenZeroAndHundred(): void
    {
        $result = $this->service->getRoomUsage();

        foreach ($result as $entry) {
            $this->assertGreaterThanOrEqual(0.0, $entry['usage_rate']);
            $this->assertLessThanOrEqual(100.0, $entry['usage_rate']);
        }
    }

    // ----------------------------------------------------------------
    // getLowUsageRooms — DB使用
    // ----------------------------------------------------------------

    public function testGetLowUsageRooms_returnsArray(): void
    {
        $result = $this->service->getLowUsageRooms(50.0);
        $this->assertIsArray($result);
    }

    public function testGetLowUsageRooms_allBelowThreshold(): void
    {
        $result = $this->service->getLowUsageRooms(50.0);

        foreach ($result as $room) {
            $this->assertLessThanOrEqual(50.0, $room['usage_rate']);
        }
    }

    public function testGetLowUsageRooms_zeroThreshold_returnsOnlyZeroUsage(): void
    {
        $result = $this->service->getLowUsageRooms(0.0);

        foreach ($result as $room) {
            $this->assertSame(0.0, $room['usage_rate']);
        }
    }

    public function testGetLowUsageRooms_hundredThreshold_returnsAll(): void
    {
        $all    = $this->service->getRoomUsage();
        $result = $this->service->getLowUsageRooms(100.0);

        $this->assertCount(count($all), $result);
    }
}
