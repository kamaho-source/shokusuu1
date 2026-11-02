<?php
declare(strict_types=1);

namespace App\Test\TestCase\Domain\Service;

use App\Domain\Service\ChangeEditPermission;
use PHPUnit\Framework\TestCase;

/**
 * 直前編集で予約を変えてよいか（ChangeEditPermission）。旧 ReservationChangeEditServiceTest の buildUsersForJson の部分を引き継ぐ。
 */
class ChangeEditPermissionTest extends TestCase
{
    public function testCanEditAll(): void
    {
        $this->assertTrue(ChangeEditPermission::canEditAll(1, false), '管理者');
        $this->assertTrue(ChangeEditPermission::canEditAll(3, false), 'システム管理者');
        $this->assertTrue(ChangeEditPermission::canEditAll(2, true), 'その部屋のブロック長');
        $this->assertFalse(ChangeEditPermission::canEditAll(2, false), '部屋に所属しないブロック長');
        $this->assertFalse(ChangeEditPermission::canEditAll(0, false), '職員は全員を編集できない（所属部屋があっても）');
    }

    public function testCanEdit(): void
    {
        $this->assertTrue(ChangeEditPermission::canEdit(true, 9, 0, 2, 0), '全員を編集できる人は他の職員も');
        $this->assertTrue(ChangeEditPermission::canEdit(false, 4, 0, 4, 0), '本人');
        $this->assertTrue(ChangeEditPermission::canEdit(false, 4, 0, 3, 1), '職員は子ども');
        $this->assertTrue(ChangeEditPermission::canEdit(false, 4, 7, 3, 1), '職員(7)も子ども');
        $this->assertFalse(ChangeEditPermission::canEdit(false, 4, 0, 2, 0), '職員は他の職員を変えられない');
        $this->assertFalse(ChangeEditPermission::canEdit(false, 4, 1, 3, 1), '子どもは他の子どもを変えられない');
        $this->assertFalse(ChangeEditPermission::canEdit(false, 4, 0, 3, null), '区分が分からない人は子どもとみなさない');
        $this->assertFalse(ChangeEditPermission::canEdit(false, 0, -1, 0, 0), '未ログインは本人とみなさない');
    }

    /** 本人以外（一括登録・直前一括編集と共通）。部屋の所属は呼び出し側で見る */
    public function testCanEditOther(): void
    {
        $this->assertTrue(ChangeEditPermission::canEditOther(true, false, 0), '全員を編集できる人は職員も');
        $this->assertTrue(ChangeEditPermission::canEditOther(false, true, 1), '職員は子ども');
        $this->assertFalse(ChangeEditPermission::canEditOther(false, true, 0), '職員は他の職員を変えられない');
        $this->assertFalse(ChangeEditPermission::canEditOther(false, false, 1), '職員でなければ子どもも変えられない');
        $this->assertFalse(ChangeEditPermission::canEditOther(false, true, null), '区分が分からない人は子どもとみなさない');
    }

    /** 直前期間の職員の予約は、管理者以外は取消・部屋の移動ができない */
    public function testIsStaffLockedInLastMinute(): void
    {
        $this->assertTrue(ChangeEditPermission::isStaffLockedInLastMinute(false, true, 0), '職員(0)');
        $this->assertTrue(ChangeEditPermission::isStaffLockedInLastMinute(false, true, 7), '職員(7)');
        $this->assertFalse(ChangeEditPermission::isStaffLockedInLastMinute(true, true, 0), '管理者は止めない');
        $this->assertFalse(ChangeEditPermission::isStaffLockedInLastMinute(false, false, 0), '直前期間でなければ止めない');
        $this->assertFalse(ChangeEditPermission::isStaffLockedInLastMinute(false, true, 1), '子どもは止めない');
        $this->assertFalse(ChangeEditPermission::isStaffLockedInLastMinute(false, true, null), '区分が分からない人は職員とみなさない');
    }
}
