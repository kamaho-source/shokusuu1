<?php
declare(strict_types=1);

namespace App\Test\TestCase\Domain\Service;

use App\Domain\Service\UserRoleRule;
use PHPUnit\Framework\TestCase;

/**
 * 権限（i_admin）を変えるときのルールのテスト。
 */
class UserRoleRuleTest extends TestCase
{
    public function testIsAssignable_systemAdminOnlyFromSystemAdminScreen(): void
    {
        foreach ([0, 1, 2] as $role) {
            $this->assertTrue(UserRoleRule::isAssignable($role, false), (string)$role);
            $this->assertTrue(UserRoleRule::isAssignable($role, true), (string)$role);
        }
        $this->assertFalse(UserRoleRule::isAssignable(3, false), '一般の画面からシステム管理者は付けられない');
        $this->assertTrue(UserRoleRule::isAssignable(3, true));
        foreach ([-1, 4, 99] as $outOfRange) {
            $this->assertFalse(UserRoleRule::isAssignable($outOfRange, true), (string)$outOfRange);
        }
    }

    public function testConflict_grantFromGeneralIsAllowed(): void
    {
        $this->assertNull(UserRoleRule::conflict(0, 1, 1, false));
    }

    public function testConflict_grantOverOtherRoleNeedsConfirmation(): void
    {
        $this->assertSame(
            '現在は「ブロック長」です。「管理者」へ変更するには確認が必要です。画面を更新してやり直してください。',
            UserRoleRule::conflict(2, 1, 1, false)
        );
        $this->assertNull(UserRoleRule::conflict(2, 1, 1, true));
    }

    public function testConflict_offOnlyAffectsOwnRole(): void
    {
        $this->assertNull(UserRoleRule::conflict(1, 0, 1, false));
        $this->assertNull(UserRoleRule::conflict(0, 0, 1, false));
        // ブロック長のユーザーに対する「管理者を解除」は他の権限を消すので拒否
        $this->assertSame(
            '現在は「ブロック長」のため、「管理者」の解除は行えません。画面を更新してやり直してください。',
            UserRoleRule::conflict(2, 0, 1, false)
        );
        $this->assertNotNull(UserRoleRule::conflict(3, 0, 2, false));
    }

    public function testConflict_sameValueIsNoChange(): void
    {
        $this->assertNull(UserRoleRule::conflict(2, 2, 2, false));
    }
}
