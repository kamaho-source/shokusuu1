<?php
declare(strict_types=1);

namespace App\Test\TestCase\Domain\Service;

use App\Domain\Service\NoticeRule;
use PHPUnit\Framework\TestCase;

/**
 * お知らせの入力の決まり（NoticeRule）。
 */
class NoticeRuleTest extends TestCase
{
    public function testError(): void
    {
        $this->assertNull(NoticeRule::error(0, 0, null, null));
        $this->assertNull(NoticeRule::error(1, 1, '2026-10-01', '2026-10-01'), '開始日と終了日が同じ日は掲載できる');
        $this->assertSame('重要度の値が不正です。', NoticeRule::error(2, 0, null, null));
        $this->assertSame('重要度の値が不正です。', NoticeRule::error(-1, 9, null, null), '重要度を先に確かめる');
        $this->assertSame('種別の値が不正です。', NoticeRule::error(0, 2, null, null));
        $this->assertSame('開始日の形式が不正です。', NoticeRule::error(0, 0, '2026/10/01', null));
        $this->assertSame('終了日の形式が不正です。', NoticeRule::error(0, 0, null, '10-31'));
        $this->assertSame('終了日は開始日以降の日付を指定してください。', NoticeRule::error(0, 0, '2026-10-31', '2026-10-30'));
        $this->assertNull(NoticeRule::error(0, 0, '2026-10-31', null));
        $this->assertNull(NoticeRule::error(0, 0, null, '2026-10-01'));
    }

    public function testResolveType(): void
    {
        $this->assertSame(1, NoticeRule::resolveType(true, 1, 0), 'システム管理者は指定どおり');
        $this->assertSame(0, NoticeRule::resolveType(true, 0, 1));
        $this->assertSame(0, NoticeRule::resolveType(false, 1, null), '管理者の登録は通常');
        $this->assertSame(1, NoticeRule::resolveType(false, 0, 1), '管理者の更新は今の種別のまま');
    }
}
