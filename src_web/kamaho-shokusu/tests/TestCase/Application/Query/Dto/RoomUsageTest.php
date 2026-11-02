<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\Query\Dto;

use App\Application\Query\Dto\RoomUsage;
use PHPUnit\Framework\TestCase;

/**
 * 部屋使用率の部屋1つ分（RoomUsage）。
 */
class RoomUsageTest extends TestCase
{
    public function testIsLow_includesThreshold(): void
    {
        $room = new RoomUsage(1, 'A', 1, 4, 2, 50.0, []);

        $this->assertTrue($room->isLow(50.0));
        $this->assertTrue($room->isLow(100.0));
        $this->assertFalse($room->isLow(49.9));
    }
}
