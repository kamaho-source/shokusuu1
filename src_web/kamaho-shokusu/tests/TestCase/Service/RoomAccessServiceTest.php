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

    public function testGetAccessibleRoomsForOfficeUserReturnsOnlyOfficeRooms(): void
    {
        $roomTable = TableRegistry::getTableLocator()->get('MRoomInfo');

        $rooms = $this->service->getAccessibleRooms($roomTable, 10);

        $this->assertSame([2 => '事務所'], $rooms);
    }

    public function testOfficeUserCanAccessOnlyOfficeRoom(): void
    {
        $this->assertTrue($this->service->userCanAccessRoom(10, 2));
        $this->assertFalse($this->service->userCanAccessRoom(10, 3));
    }
}