<?php
declare(strict_types=1);

namespace App\Test\TestCase\Domain\Entity;

use App\Domain\Entity\RoomTransferSchedule;
use PHPUnit\Framework\TestCase;

/**
 * 部屋異動の予約。異動元と異動先が同じ部屋の異動は、行えない（適用すると予約が消える。#945）。
 */
class RoomTransferScheduleTest extends TestCase
{
    public function testIsSameRoom(): void
    {
        $this->assertTrue(RoomTransferSchedule::isSameRoom(910, 910));
        $this->assertFalse(RoomTransferSchedule::isSameRoom(910, 911));
        $this->assertFalse(RoomTransferSchedule::isSameRoom(null, 910), '新しい配属（異動元なし）は同じ部屋ではない');
    }

    public function testScheduleKnowsItIsSameRoom(): void
    {
        $this->assertTrue((new RoomTransferSchedule(1, 20, 910, 910, '2026-11-10', RoomTransferSchedule::STATUS_PENDING))->isSameRoomTransfer());
        $this->assertFalse((new RoomTransferSchedule(1, 20, 910, 911, '2026-11-10', RoomTransferSchedule::STATUS_PENDING))->isSameRoomTransfer());
    }
}
