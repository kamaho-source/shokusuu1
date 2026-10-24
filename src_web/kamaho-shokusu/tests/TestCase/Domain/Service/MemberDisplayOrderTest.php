<?php
declare(strict_types=1);

namespace App\Test\TestCase\Domain\Service;

use App\Domain\Exception\InvalidInputException;
use App\Domain\Service\MemberDisplayOrder;
use PHPUnit\Framework\TestCase;

class MemberDisplayOrderTest extends TestCase
{
    public function testReassignsExistingNumbersInNewOrder(): void
    {
        // 部屋の人が持つ番号 3, 8, 12 を、並べ直した順に配り直す（他の部屋の人の番号の間を空けたまま）
        $changes = MemberDisplayOrder::reassign([10 => 3, 20 => 8, 30 => 12], [30, 10, 20], 50);

        $this->assertSame([30 => 3, 10 => 8, 20 => 12], $changes);
    }

    public function testUnchangedOrderReturnsNoChanges(): void
    {
        $this->assertSame([], MemberDisplayOrder::reassign([10 => 1, 20 => 2], [10, 20], 5));
    }

    public function testNullAndDuplicateNumbersGetNewNumbersAfterMax(): void
    {
        $changes = MemberDisplayOrder::reassign([10 => 4, 20 => 4, 30 => null], [30, 20, 10], 9);

        // 使える番号は 4 だけ。足りない分は最大値 9 の後ろ（10, 11）
        $this->assertSame([30 => 4, 20 => 10, 10 => 11], $changes);
    }

    public function testRejectsDifferentMembers(): void
    {
        $this->expectException(InvalidInputException::class);
        MemberDisplayOrder::reassign([10 => 1, 20 => 2], [10, 99], 5);
    }

    public function testRejectsMissingMember(): void
    {
        $this->expectException(InvalidInputException::class);
        MemberDisplayOrder::reassign([10 => 1, 20 => 2], [10], 5);
    }
}
