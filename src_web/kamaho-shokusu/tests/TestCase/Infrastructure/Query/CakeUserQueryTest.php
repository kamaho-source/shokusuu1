<?php
declare(strict_types=1);

namespace App\Test\TestCase\Infrastructure\Query;

use App\Infrastructure\Query\CakeUserQuery;
use App\Infrastructure\Repository\CakeRoomMembershipRepository;
use App\Infrastructure\Repository\CakeUserAccountRepository;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * CakeUserQuery を本物の Repository につないで確かめる（UseCase の読み取りメソッドのテストを引き継ぐ）。
 * フィクスチャ：利用者 1〜5 は削除されていない、6 は削除済み。部屋 1 に 1・2・3・6 が所属。
 */
class CakeUserQueryTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
    ];

    private function query(): CakeUserQuery
    {
        return new CakeUserQuery(new CakeUserAccountRepository($this->getTableLocator()->get('MUserInfo')), new CakeRoomMembershipRepository());
    }

    public function testFind_includesDeletedAndNullForMissing(): void
    {
        $this->assertSame('削除済みユーザー', $this->query()->find(6)?->name);
        $this->assertTrue($this->query()->find(6)?->isDeleted());
        $this->assertNull($this->query()->find(999));
    }

    public function testExists_includesDeleted(): void
    {
        $this->assertTrue($this->query()->exists(1));
        $this->assertTrue($this->query()->exists(6));
        $this->assertFalse($this->query()->exists(999));
    }

    public function testFindByLoginAccount_includesDeletedAndNullForMissing(): void
    {
        $this->assertSame(1, $this->query()->findByLoginAccount('admin_user')?->id);
        $this->assertSame('削除済みユーザー', $this->query()->findByLoginAccount('deleted_user')?->name);
        $this->assertNull($this->query()->findByLoginAccount('no_such_account'));
    }

    /** 所属中の部屋名（退出済み・削除済みの部屋は出さない）。居ない利用者は空、渡した順 */
    public function testActiveRoomNames_onlyCurrentRoomsInGivenOrder(): void
    {
        $db = ConnectionManager::get('test');
        $base = ['i_disp_no' => 9, 'i_enable' => 1];
        $db->insert('m_room_info', ['i_id_room' => 2, 'c_room_name' => '退出した部屋', 'i_del_flg' => 0] + $base);
        $db->insert('m_room_info', ['i_id_room' => 3, 'c_room_name' => '削除済みの部屋', 'i_del_flg' => 1] + $base);
        $db->insert('m_user_group', ['i_id_user' => 1, 'i_id_room' => 2, 'active_flag' => 1]);
        $db->insert('m_user_group', ['i_id_user' => 1, 'i_id_room' => 3, 'active_flag' => 0]);

        $names = $this->query()->activeRoomNames([999, 4, 1]);
        $this->assertSame([999, 4, 1], array_keys($names));
        $this->assertSame(['Lorem ipsum dolor sit amet'], $names[1]);
        $this->assertSame([], $names[4], '所属なし');
        $this->assertSame([], $names[999]);
        $this->assertSame([], $this->query()->activeRoomNames([]));
    }

    /** 選択済みにする部屋は所属中（active_flag=0・NULL）の所属。退出済みは含めず、削除済みの部屋は含める（従来どおり） */
    public function testCurrentRoomIds_activeMembershipsIncludingDeletedRooms(): void
    {
        $db = ConnectionManager::get('test');
        $db->insert('m_user_group', ['i_id_user' => 1, 'i_id_room' => 2, 'active_flag' => 1]);
        $db->insert('m_user_group', ['i_id_user' => 1, 'i_id_room' => 3, 'active_flag' => 0]);

        $ids = $this->query()->currentRoomIds(1);
        sort($ids);
        $this->assertSame([1, 3], $ids);
        $this->assertSame([], $this->query()->currentRoomIds(999));
    }

    public function testIsActive_excludesDeletedAndMissing(): void
    {
        $this->assertTrue($this->query()->isActive(1));
        $this->assertFalse($this->query()->isActive(6));
        $this->assertFalse($this->query()->isActive(999));
    }

    /** 選べる部屋は、管理者・システム管理者も含めて自分が所属している削除されていない部屋だけ（#821） */
    public function testSelectableRoomsFor_onlyActorsActiveRooms(): void
    {
        $db = ConnectionManager::get('test');
        $base = ['i_disp_no' => 9, 'i_enable' => 1];
        $db->insert('m_room_info', ['i_id_room' => 2, 'c_room_name' => '所属外の部屋', 'i_del_flg' => 0] + $base);
        $db->insert('m_room_info', ['i_id_room' => 3, 'c_room_name' => '削除済みの部屋', 'i_del_flg' => 1] + $base);
        $db->insert('m_user_group', ['i_id_user' => 1, 'i_id_room' => 3, 'active_flag' => 0]);
        $db->insert('m_user_group', ['i_id_user' => 1, 'i_id_room' => 2, 'active_flag' => 1]);

        $this->assertSame([1], array_keys($this->query()->selectableRoomsFor(1)), '管理者も所属している部屋だけ（削除済み・異動で外れた部屋は除く）');
        $this->assertSame([1], array_keys($this->query()->selectableRoomsFor(2)), '職員は自分が所属している部屋だけ');
        $this->assertSame([], $this->query()->selectableRoomsFor(4), '所属部屋の無いシステム管理者は選べる部屋が無い');
    }

    public function testPasswordResetTargets_excludesDeleted(): void
    {
        $targets = $this->query()->passwordResetTargets();

        $this->assertSame([1, 2, 3, 4, 5], array_keys($targets));
        $this->assertSame('管理者ユーザー', $targets[1]);
    }
}
