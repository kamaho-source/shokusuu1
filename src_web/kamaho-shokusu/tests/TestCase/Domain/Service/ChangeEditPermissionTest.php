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
}
