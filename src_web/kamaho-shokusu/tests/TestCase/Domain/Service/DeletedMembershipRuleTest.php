<?php
declare(strict_types=1);

namespace App\Test\TestCase\Domain\Service;

use App\Domain\Service\DeletedMembershipRule;
use PHPUnit\Framework\TestCase;

/**
 * 「利用者の削除で外した所属」かどうかのルールのテスト。
 */
class DeletedMembershipRuleTest extends TestCase
{
    private static function t(string $s): \DateTimeImmutable
    {
        return new \DateTimeImmutable($s);
    }

    public function testActiveRows_areNeverLeftByDeletion(): void
    {
        $this->assertFalse(DeletedMembershipRule::isLeftByDeletion(0, 1, true, null, null));
        $this->assertFalse(DeletedMembershipRule::isLeftByDeletion(null, 1, true, null, null));
    }

    public function testWithMarks_onlyMarkedRowsCount(): void
    {
        $deleted = self::t('2026-11-01 09:00:00');
        $this->assertTrue(DeletedMembershipRule::isLeftByDeletion(1, 1, true, self::t('2026-04-01 10:00:00'), $deleted), '印があれば時刻に関係なく対象');
        $this->assertFalse(DeletedMembershipRule::isLeftByDeletion(1, null, true, $deleted, $deleted), '印のある人では、印の無い行は部屋異動などで外れた行');
    }

    public function testWithoutMarks_rowsLeftAtTheDeletionTimeCount(): void
    {
        $deleted = self::t('2025-09-20 16:04:14');
        $this->assertTrue(DeletedMembershipRule::isLeftByDeletion(1, null, false, self::t('2025-09-20 16:04:14'), $deleted));
        $this->assertTrue(DeletedMembershipRule::isLeftByDeletion(1, null, false, self::t('2025-09-20 16:04:19'), $deleted), '5秒までは同じ時刻とみなす');
        $this->assertFalse(DeletedMembershipRule::isLeftByDeletion(1, null, false, self::t('2025-09-20 16:04:20'), $deleted), '6秒ずれたら別の時刻');
        $this->assertFalse(DeletedMembershipRule::isLeftByDeletion(1, null, false, self::t('2025-04-01 10:00:00'), $deleted), '部屋異動で外れた昔の行');
        $this->assertFalse(DeletedMembershipRule::isLeftByDeletion(1, null, false, null, $deleted), '時刻が無ければ見分けられない');
    }
}
