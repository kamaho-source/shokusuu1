<?php
declare(strict_types=1);

namespace App\Test\TestCase\Domain\Service;

use App\Domain\Service\ApprovalRule;
use App\Domain\ValueObject\ApprovalKey;
use App\Domain\ValueObject\ApprovalStatus;
use PHPUnit\Framework\TestCase;

class ApprovalRuleTest extends TestCase
{
    public function testBlockLeader_excludesOwnReservationAndOutOfScopeRooms(): void
    {
        foreach ([ApprovalRule::blockLeaderApprove(5, [1, 2]), ApprovalRule::blockLeaderReject(5, [1, 2])] as $rule) {
            $this->assertTrue($rule->covers(new ApprovalKey(6, '2024-09-07', 2, 1)));
            $this->assertFalse($rule->covers(new ApprovalKey(5, '2024-09-07', 1, 1)), '自分の予約は除く');
            $this->assertFalse($rule->covers(new ApprovalKey(6, '2024-09-07', 3, 1)), '担当外の部屋は除く');
        }
        $this->assertFalse(ApprovalRule::blockLeaderApprove(5, [])->covers(new ApprovalKey(6, '2024-09-07', 1, 1)), '担当部屋が無ければ何も対象にしない');
    }

    public function testBlockLeaderApprove_byAdmin_coversAllRoomsButNotOwn(): void
    {
        $rule = ApprovalRule::blockLeaderApprove(5, null);

        $this->assertTrue($rule->covers(new ApprovalKey(6, '2024-09-07', 99, 1)));
        $this->assertFalse($rule->covers(new ApprovalKey(5, '2024-09-07', 99, 1)));
    }

    public function testAdmin_coversOwnReservationAndAllRooms(): void
    {
        foreach ([ApprovalRule::adminApprove(), ApprovalRule::adminReject()] as $rule) {
            $this->assertTrue($rule->covers(new ApprovalKey(5, '2024-09-07', 99, 1)));
        }
    }

    public function testStatuses(): void
    {
        $this->assertSame([ApprovalStatus::BLOCK_LEADER, [0]], [ApprovalRule::blockLeaderApprove(1, null)->newStatus, ApprovalRule::blockLeaderApprove(1, null)->allowedFrom]);
        $this->assertSame([ApprovalStatus::ADMIN, [0, 1]], [ApprovalRule::adminApprove()->newStatus, ApprovalRule::adminApprove()->allowedFrom]);
        $this->assertSame([ApprovalStatus::REJECTED, [0, 1]], [ApprovalRule::adminReject()->newStatus, ApprovalRule::adminReject()->allowedFrom]);
        $this->assertSame([ApprovalStatus::REJECTED, [0, 1]], [ApprovalRule::blockLeaderReject(1, [])->newStatus, ApprovalRule::blockLeaderReject(1, [])->allowedFrom]);
    }
}
