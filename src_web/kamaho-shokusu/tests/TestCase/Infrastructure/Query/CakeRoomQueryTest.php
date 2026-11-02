<?php
declare(strict_types=1);

namespace App\Test\TestCase\Infrastructure\Query;

use App\Infrastructure\Query\CakeRoomQuery;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

class CakeRoomQueryTest extends TestCase
{
    protected array $fixtures = [
        'app.MRoomInfo',
        'app.MUserInfo',
        'app.MUserGroup',
    ];

    private CakeRoomQuery $service;

    public function setUp(): void
    {
        parent::setUp();
        $this->service = new CakeRoomQuery();

        $connection = ConnectionManager::get('test');
        $now = DateTime::now('Asia/Tokyo')->format('Y-m-d H:i:s');

        $connection->insert('m_room_info', [
            'i_id_room' => 2,
            'c_room_name' => '事務所',
            'i_disp_no' => 2,
            'i_enable' => 1,
            'i_del_flg' => 0,
            'dt_create' => $now,
            'dt_update' => $now,
        ]);
        $connection->insert('m_room_info', [
            'i_id_room' => 3,
            'c_room_name' => '居室A',
            'i_disp_no' => 3,
            'i_enable' => 1,
            'i_del_flg' => 0,
            'dt_create' => $now,
            'dt_update' => $now,
        ]);
        $connection->insert('m_user_info', [
            'i_id_user' => 10,
            'c_login_account' => 'office-user',
            'c_login_passwd' => 'dummy',
            'c_user_name' => 'Office User',
            'i_admin' => 0,
            'i_user_level' => 0,
            'i_enable' => 1,
            'i_del_flag' => 0,
            'dt_create' => $now,
            'dt_update' => $now,
        ]);
        $connection->insert('m_user_group', [
            'i_id_user' => 10,
            'i_id_room' => 2,
            'active_flag' => 0,
            'dt_create' => $now,
            'dt_update' => $now,
        ]);
        $connection->insert('m_user_group', [
            'i_id_user' => 10,
            'i_id_room' => 3,
            'active_flag' => 0,
            'dt_create' => $now,
            'dt_update' => $now,
        ]);
    }

    public function testGetAccessibleRoomsReturnsAllAssignedRooms(): void
    {
        $roomTable = TableRegistry::getTableLocator()->get('MRoomInfo');

        $rooms = $this->service->getAccessibleRooms(10);

        $this->assertSame([2 => '事務所', 3 => '居室A'], $rooms);
    }

    public function testGetAccessibleRoomsReturnsEmptyForUnknownUser(): void
    {
        $roomTable = TableRegistry::getTableLocator()->get('MRoomInfo');

        $rooms = $this->service->getAccessibleRooms(0);

        $this->assertSame([], $rooms);
    }

    public function testUserCanAccessAllAssignedRooms(): void
    {
        $this->assertTrue($this->service->isActiveMember(10, 2));
        $this->assertTrue($this->service->isActiveMember(10, 3));
    }

    public function testUserCannotAccessUnassignedRoom(): void
    {
        $this->assertFalse($this->service->isActiveMember(10, 99));
    }

    public function testGetRoomsByIdsReturnsMatchingRooms(): void
    {
        $rooms = $this->service->getRoomsByIds([2, 3]);

        $this->assertSame([2 => '事務所', 3 => '居室A'], $rooms);
    }

    public function testGetRoomsByIdsFiltersDeletedRooms(): void
    {
        $connection = ConnectionManager::get('test');
        $now = DateTime::now('Asia/Tokyo')->format('Y-m-d H:i:s');
        $connection->insert('m_room_info', [
            'i_id_room' => 99,
            'c_room_name' => '削除済み部屋',
            'i_disp_no' => 99,
            'i_enable' => 0,
            'i_del_flg' => 1,
            'dt_create' => $now,
            'dt_update' => $now,
        ]);

        $rooms = $this->service->getRoomsByIds([2, 99]);

        $this->assertArrayHasKey(2, $rooms);
        $this->assertArrayNotHasKey(99, $rooms);
    }

    public function testGetRoomsByIdsReturnsEmptyForEmptyInput(): void
    {
        $this->assertSame([], $this->service->getRoomsByIds([]));
    }

    public function testGetAllActiveRoomsReturnsOnlyActiveRooms(): void
    {
        $connection = ConnectionManager::get('test');
        $now = DateTime::now('Asia/Tokyo')->format('Y-m-d H:i:s');
        $connection->insert('m_room_info', [
            'i_id_room' => 98,
            'c_room_name' => '削除済み部屋',
            'i_disp_no' => 98,
            'i_enable' => 0,
            'i_del_flg' => 1,
            'dt_create' => $now,
            'dt_update' => $now,
        ]);

        $rooms = $this->service->getAllActiveRooms();

        $this->assertArrayHasKey(2, $rooms);
        $this->assertArrayHasKey(3, $rooms);
        $this->assertArrayNotHasKey(98, $rooms);
    }

    // ----------------------------------------------------------------
    // isActiveMember（予約の書き込み可否の判定: 有効な所属か）
    // ----------------------------------------------------------------

    private function addMembership(int $userId, int $roomId, int $activeFlag, int $roomDelFlg = 0): void
    {
        $now = DateTime::now('Asia/Tokyo')->format('Y-m-d H:i:s');
        $db  = ConnectionManager::get('test');
        $db->insert('m_room_info', [
            'i_id_room' => $roomId, 'c_room_name' => '部屋' . $roomId, 'i_disp_no' => $roomId,
            'i_enable' => 1, 'i_del_flg' => $roomDelFlg, 'dt_create' => $now, 'dt_update' => $now,
        ]);
        $db->insert('m_user_group', [
            'i_id_user' => $userId, 'i_id_room' => $roomId, 'active_flag' => $activeFlag,
            'dt_create' => $now, 'dt_update' => $now,
        ]);
    }

    public function testIsActiveMember_activeMembershipInLiveRoom_true(): void
    {
        $this->assertTrue($this->service->isActiveMember(10, 2));
        $this->assertTrue($this->service->isActiveMember(10, 3));
    }

    public function testIsActiveMember_leftRoom_false(): void
    {
        $this->addMembership(10, 21, 1); // 退出済み(active_flag=1)

        $this->assertFalse($this->service->isActiveMember(10, 21));
    }

    public function testIsActiveMember_deletedRoom_false(): void
    {
        $this->addMembership(10, 22, 0, 1); // 所属は有効だが部屋が削除済み

        $this->assertFalse($this->service->isActiveMember(10, 22));
    }

    public function testIsActiveMember_notAMember_false(): void
    {
        $this->assertFalse($this->service->isActiveMember(10, 99));
    }

    public function testIsActiveMember_invalidIds_false(): void
    {
        $this->assertFalse($this->service->isActiveMember(0, 2));
        $this->assertFalse($this->service->isActiveMember(10, 0));
    }

    public function testGetAccessibleRooms_excludesDeletedRooms(): void
    {
        $this->addMembership(10, 23, 0, 1); // 所属は有効だが部屋が削除済み

        $rooms = $this->service->getAccessibleRooms(10);

        $this->assertArrayNotHasKey(23, $rooms);
        $this->assertSame([2, 3], array_keys($rooms));
    }

    public function testUserCanAccessRoom_deletedRoom_false(): void
    {
        $this->addMembership(10, 24, 0, 1);

        $this->assertFalse($this->service->isActiveMember(10, 24));
        $this->assertTrue($this->service->isActiveMember(10, 2));
    }

    public function testIsRoomAvailable_deletedAndMissing(): void
    {
        $this->addMembership(10, 25, 0, 1);

        $this->assertFalse($this->service->isRoomAvailable(25));
        $this->assertTrue($this->service->isRoomAvailable(2));
        // 存在しない部屋は利用可能ではない
        $this->assertFalse($this->service->isRoomAvailable(9999));
    }

    public function testGetUserRoomIds_returnsActiveRoomsOnly(): void
    {
        $this->addMembership(10, 31, 1); // 退出済みは含めない

        $ids = $this->service->getUserRoomIds(10);

        sort($ids);
        $this->assertSame([2, 3], $ids);
        $this->assertSame([], $this->service->getUserRoomIds(0));
        $this->assertSame([], $this->service->getUserRoomIds(9999));
    }

    public function testHasAnyAffiliation(): void
    {
        $this->assertTrue($this->service->hasAnyAffiliation(10));
        $this->assertFalse($this->service->hasAnyAffiliation(9999));
    }

    public function testIsOfficeUser(): void
    {
        $this->assertTrue($this->service->isOfficeUser(10));
        $this->assertFalse($this->service->isOfficeUser(0));
        $this->assertFalse($this->service->isOfficeUser(9999));
    }

    public function testIsOfficeUser_deletedOfficeRoomIsNotOffice(): void
    {
        $connection = ConnectionManager::get('test');
        $connection->update('m_room_info', ['i_del_flg' => 1], ['i_id_room' => 2]);

        $this->assertFalse($this->service->isOfficeUser(10));
    }

    public function testGetOfficeRoomIds(): void
    {
        $this->assertSame([2], $this->service->getOfficeRoomIds(10));
        $this->assertSame([], $this->service->getOfficeRoomIds(0));
        $this->assertSame([], $this->service->getOfficeRoomIds(9999));
    }

    public function testUserCanAccessRoom_invalidIds(): void
    {
        $this->assertFalse($this->service->isActiveMember(0, 2));
        $this->assertFalse($this->service->isActiveMember(10, 0));
    }

    public function testIsRoomAvailable_zeroId(): void
    {
        $this->assertFalse($this->service->isRoomAvailable(0));
    }

    public function testGetAccessibleRooms_ordersByDispNo(): void
    {
        $this->addMembership(10, 40, 0);
        $connection = ConnectionManager::get('test');
        $connection->update('m_room_info', ['i_disp_no' => 0], ['i_id_room' => 40]);

        $rooms = $this->service->getAccessibleRooms(10);

        $this->assertSame([40, 2, 3], array_keys($rooms));
    }

    public function testDbFailureFallsBackToSafeDefaults(): void
    {
        // 取得時に例外を投げるテーブルに差し替え、例外が握られて安全側の値になることを確認する
        foreach (['MUserGroup', 'MRoomInfo'] as $alias) {
            $table = $this->createStub(\Cake\ORM\Table::class);
            $table->method('find')->willThrowException(new \RuntimeException('boom'));
            TableRegistry::getTableLocator()->set($alias, $table);
        }

        $this->assertSame([], $this->service->getUserRoomIds(10));
        $this->assertFalse($this->service->isOfficeUser(10));
        $this->assertSame([], $this->service->getOfficeRoomIds(10));
        $this->assertSame([], $this->service->getRoomsByIds([2]));
        $this->assertSame([], $this->service->getAllActiveRooms());
    }

    // ----------------------------------------------------------------
    // 部屋のメンバー・部屋の数（旧 RoomServiceTest を引き継ぐ）
    // ----------------------------------------------------------------

    /** 部屋のメンバーは所属中で、削除されていない人だけ（削除済み 6、異動で抜けた人は出ない） */
    public function testGetRoomMembers_activeAndNotDeletedOnly(): void
    {
        $groups = TableRegistry::getTableLocator()->get('MUserGroup');
        $expected = array_values(array_diff(array_map('intval', $groups->find()->where(['i_id_room' => 1, 'active_flag' => 0])->all()->extract('i_id_user')->toList()), [6]));
        $ids = array_map(static fn($m) => $m->userId, $this->service->getRoomMembers(1));
        sort($expected);
        $sorted = $ids;
        sort($sorted);
        $this->assertNotEmpty($expected);
        $this->assertSame($expected, $sorted);

        $groups->updateAll(['active_flag' => 1], ['i_id_user' => $ids[0], 'i_id_room' => 1]);
        $this->assertNotContains($ids[0], array_map(static fn($m) => $m->userId, $this->service->getRoomMembers(1)), '異動で抜けた人は出ない');
    }

    public function testGetRoomMembers_roomWithoutMembers_isEmpty(): void
    {
        $this->assertSame([], $this->service->getRoomMembers(9999));
    }

    public function testGetRoomMembers_carriesProfileForTheScreen(): void
    {
        TableRegistry::getTableLocator()->get('MUserInfo')->updateAll(['i_user_age' => 9, 'i_user_level' => 1, 'i_disp_no' => 3], ['i_id_user' => 1]);
        $member = array_values(array_filter($this->service->getRoomMembers(1), static fn($m) => $m->userId === 1))[0] ?? null;

        $this->assertNotNull($member);
        $this->assertSame([9, 1, 3], [$member->age, $member->level, $member->displayNo]);
        $this->assertSame(TableRegistry::getTableLocator()->get('MUserInfo')->get(1)->c_user_name, $member->name);
    }

    public function testCountActiveRooms_excludesDeletedRooms(): void
    {
        $before = $this->service->countActiveRooms();
        TableRegistry::getTableLocator()->get('MRoomInfo')->updateAll(['i_del_flg' => 1], ['i_id_room' => 1]);

        $this->assertSame($before - 1, $this->service->countActiveRooms());
    }
}
