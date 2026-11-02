<?php
declare(strict_types=1);

namespace App\Test\TestCase\Domain\ValueObject;

use App\Domain\ValueObject\RoomMembershipStatus;
use App\Infrastructure\Persistence\Table\MUserGroupTable;
use PHPUnit\Framework\TestCase;

/**
 * 部屋の所属の状態（active_flag は 0 が所属中）と、そのクエリ条件。
 */
class RoomMembershipStatusTest extends TestCase
{
    public function testIsActive(): void
    {
        $this->assertTrue(RoomMembershipStatus::isActive(0));
        $this->assertTrue(RoomMembershipStatus::isActive('0'));
        $this->assertTrue(RoomMembershipStatus::isActive(null), '従来どおり NULL は所属中とみなす');
        $this->assertFalse(RoomMembershipStatus::isActive(1));
        $this->assertFalse(RoomMembershipStatus::isActive('1'));
    }

    public function testActiveCondition(): void
    {
        $this->assertSame(['MUserGroup.active_flag' => 0], MUserGroupTable::activeCondition());
        $this->assertSame(['UG.active_flag' => 0], MUserGroupTable::activeCondition('UG'));
        $this->assertSame(['active_flag' => 0], MUserGroupTable::activeCondition(''));
    }
}
