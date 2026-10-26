<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\RoomAccessService;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

class RoomAccessServiceTest extends TestCase
{
    protected array $fixtures = [
        'app.MRoomInfo',
        'app.MUserInfo',
        'app.MUserGroup',
    ];

    private RoomAccessService $service;

    public function setUp(): void
    {
        parent::setUp();
        $this->service = new RoomAccessService();

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

        $rooms = $this->service->getAccessibleRooms($roomTable, 10);

        $this->assertSame([2 => '事務所', 3 => '居室A'], $rooms);
    }

    public function testGetAccessibleRoomsReturnsEmptyForUnknownUser(): void
    {
        $roomTable = TableRegistry::getTableLocator()->get('MRoomInfo');

        $rooms = $this->service->getAccessibleRooms($roomTable, 0);

        $this->assertSame([], $rooms);
    }

    public function testUserCanAccessAllAssignedRooms(): void
    {
        $this->assertTrue($this->service->userCanAccessRoom(10, 2));
        $this->assertTrue($this->service->userCanAccessRoom(10, 3));
    }

    public function testUserCannotAccessUnassignedRoom(): void
    {
        $this->assertFalse($this->service->userCanAccessRoom(10, 99));
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

        $rooms = $this->service->getAccessibleRooms(TableRegistry::getTableLocator()->get('MRoomInfo'), 10);

        $this->assertArrayNotHasKey(23, $rooms);
        $this->assertSame([2, 3], array_keys($rooms));
    }

    public function testUserCanAccessRoom_deletedRoom_false(): void
    {
        $this->addMembership(10, 24, 0, 1);

        $this->assertFalse($this->service->userCanAccessRoom(10, 24));
        $this->assertTrue($this->service->userCanAccessRoom(10, 2));
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
        $this->assertFalse($this->service->userCanAccessRoom(0, 2));
        $this->assertFalse($this->service->userCanAccessRoom(10, 0));
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

        $rooms = $this->service->getAccessibleRooms(TableRegistry::getTableLocator()->get('MRoomInfo'), 10);

        $this->assertSame([40, 2, 3], array_keys($rooms));
    }

    public function testGetOfficeRooms_privateReturnsOfficeRoomsOnly(): void
    {
        $roomTable = TableRegistry::getTableLocator()->get('MRoomInfo');
        $m = new \ReflectionMethod($this->service, 'getOfficeRooms');
        $m->setAccessible(true);

        $this->assertSame([2 => '事務所'], $m->invoke($this->service, $roomTable, 10));
        $this->assertSame([], $m->invoke($this->service, $roomTable, 9999));
    }

    public function testBuildRoomOrder_prefersDispNoColumn(): void
    {
        $roomTable = TableRegistry::getTableLocator()->get('MRoomInfo');
        $m = new \ReflectionMethod($this->service, 'buildRoomOrder');
        $m->setAccessible(true);

        $order = $m->invoke($this->service, $roomTable);

        $this->assertSame('i_id_room', array_key_last($order));
        $this->assertCount(2, $order);
    }

    public function testBuildRoomOrder_fallsBackWhenSchemaFails(): void
    {
        $table = $this->createStub(\Cake\ORM\Table::class);
        $table->method('getSchema')->willThrowException(new \RuntimeException('boom'));
        $m = new \ReflectionMethod($this->service, 'buildRoomOrder');
        $m->setAccessible(true);

        $this->assertSame(['i_id_room' => 'ASC'], $m->invoke($this->service, $table));
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
}
