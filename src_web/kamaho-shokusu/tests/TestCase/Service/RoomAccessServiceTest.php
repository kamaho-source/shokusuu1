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
            'i_id_room' => 101,
            'c_room_name' => '事務所',
            'i_disp_no' => 101,
            'i_enable' => 1,
            'i_del_flg' => 0,
            'dt_create' => $now,
            'dt_update' => $now,
        ]);
        $connection->insert('m_room_info', [
            'i_id_room' => 102,
            'c_room_name' => '居室A',
            'i_disp_no' => 102,
            'i_enable' => 1,
            'i_del_flg' => 0,
            'dt_create' => $now,
            'dt_update' => $now,
        ]);
        $connection->insert('m_user_info', [
            'i_id_user' => 100,
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
            'i_id_user' => 100,
            'i_id_room' => 101,
            'active_flag' => 0,
            'dt_create' => $now,
            'dt_update' => $now,
        ]);
        $connection->insert('m_user_group', [
            'i_id_user' => 100,
            'i_id_room' => 102,
            'active_flag' => 0,
            'dt_create' => $now,
            'dt_update' => $now,
        ]);
    }

    public function testGetAccessibleRoomsReturnsAllAssignedRooms(): void
    {
        $roomTable = TableRegistry::getTableLocator()->get('MRoomInfo');

        $rooms = $this->service->getAccessibleRooms($roomTable, 100);

        $this->assertSame([101 => '事務所', 102 => '居室A'], $rooms);
    }

    public function testGetAccessibleRoomsReturnsEmptyForUnknownUser(): void
    {
        $roomTable = TableRegistry::getTableLocator()->get('MRoomInfo');

        $rooms = $this->service->getAccessibleRooms($roomTable, 0);

        $this->assertSame([], $rooms);
    }

    public function testUserCanAccessAllAssignedRooms(): void
    {
        $this->assertTrue($this->service->userCanAccessRoom(100, 101));
        $this->assertTrue($this->service->userCanAccessRoom(100, 102));
    }

    public function testUserCannotAccessUnassignedRoom(): void
    {
        $this->assertFalse($this->service->userCanAccessRoom(100, 999));
    }

    public function testGetRoomsByIdsReturnsMatchingRooms(): void
    {
        $rooms = $this->service->getRoomsByIds([101, 102]);

        $this->assertSame([101 => '事務所', 102 => '居室A'], $rooms);
    }

    public function testGetRoomsByIdsFiltersDeletedRooms(): void
    {
        $connection = ConnectionManager::get('test');
        $now = DateTime::now('Asia/Tokyo')->format('Y-m-d H:i:s');
        $connection->insert('m_room_info', [
            'i_id_room' => 199,
            'c_room_name' => '削除済み部屋',
            'i_disp_no' => 199,
            'i_enable' => 0,
            'i_del_flg' => 1,
            'dt_create' => $now,
            'dt_update' => $now,
        ]);

        $rooms = $this->service->getRoomsByIds([101, 199]);

        $this->assertArrayHasKey(101, $rooms);
        $this->assertArrayNotHasKey(199, $rooms);
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
            'i_id_room' => 198,
            'c_room_name' => '削除済み部屋',
            'i_disp_no' => 198,
            'i_enable' => 0,
            'i_del_flg' => 1,
            'dt_create' => $now,
            'dt_update' => $now,
        ]);

        $rooms = $this->service->getAllActiveRooms();

        $this->assertArrayHasKey(101, $rooms);
        $this->assertArrayHasKey(102, $rooms);
        $this->assertArrayNotHasKey(198, $rooms);
    }
}
