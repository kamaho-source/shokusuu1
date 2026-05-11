<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ReservationCalendarService;
use Cake\TestSuite\TestCase;

/**
 * ReservationCalendarService テスト
 *
 * 対象メソッド:
 *   - getAllActiveRoomIds() — i_del_flg=0 の部屋IDを返す
 *   - getRoomsByIds()       — 指定IDの部屋を [id => name] で返す
 */
class ReservationCalendarServiceTest extends TestCase
{
    protected array $fixtures = [
        'app.MRoomInfo',
    ];

    private ReservationCalendarService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ReservationCalendarService();
    }

    /* =====================================================================
     * getAllActiveRoomIds
     * ===================================================================== */

    public function testGetAllActiveRoomIdsReturnsActiveRooms(): void
    {
        $roomTable = $this->getTableLocator()->get('MRoomInfo');

        $result = $this->service->getAllActiveRoomIds($roomTable);

        $this->assertContains(1, $result);
    }

    public function testGetAllActiveRoomIdsExcludesDeletedRooms(): void
    {
        $roomTable = $this->getTableLocator()->get('MRoomInfo');

        // 削除済み部屋を動的に追加
        $deleted = $roomTable->newEntity([
            'i_id_room'     => 99,
            'c_room_name'   => 'Deleted Room',
            'i_disp_no'     => 99,
            'i_enable'      => 1,
            'i_del_flg'     => 1,
            'dt_create'     => '2024-01-01 00:00:00',
            'c_create_user' => 'test',
            'dt_update'     => '2024-01-01 00:00:00',
            'c_update_user' => 'test',
        ]);
        $roomTable->saveOrFail($deleted);

        $result = $this->service->getAllActiveRoomIds($roomTable);

        $this->assertContains(1, $result);
        $this->assertNotContains(99, $result);
    }

    public function testGetAllActiveRoomIdsReturnsArrayOfInts(): void
    {
        $roomTable = $this->getTableLocator()->get('MRoomInfo');

        $result = $this->service->getAllActiveRoomIds($roomTable);

        $this->assertIsArray($result);
        foreach ($result as $id) {
            $this->assertIsInt($id);
        }
    }

    /* =====================================================================
     * getRoomsByIds
     * ===================================================================== */

    public function testGetRoomsByIdsReturnsIdToNameMap(): void
    {
        $roomTable = $this->getTableLocator()->get('MRoomInfo');

        $result = $this->service->getRoomsByIds($roomTable, [1]);

        $this->assertArrayHasKey(1, $result);
        $this->assertSame('Lorem ipsum dolor sit amet', $result[1]);
    }

    public function testGetRoomsByIdsReturnsEmptyForEmptyInput(): void
    {
        $roomTable = $this->getTableLocator()->get('MRoomInfo');

        $result = $this->service->getRoomsByIds($roomTable, []);

        $this->assertSame([], $result);
    }

    public function testGetRoomsByIdsIgnoresNonExistentIds(): void
    {
        $roomTable = $this->getTableLocator()->get('MRoomInfo');

        $result = $this->service->getRoomsByIds($roomTable, [999]);

        $this->assertSame([], $result);
    }

    public function testGetRoomsByIdsFiltersOutDeletedRooms(): void
    {
        $roomTable = $this->getTableLocator()->get('MRoomInfo');

        $deleted = $roomTable->newEntity([
            'i_id_room'     => 88,
            'c_room_name'   => 'Deleted Room',
            'i_disp_no'     => 88,
            'i_enable'      => 1,
            'i_del_flg'     => 1,
            'dt_create'     => '2024-01-01 00:00:00',
            'c_create_user' => 'test',
            'dt_update'     => '2024-01-01 00:00:00',
            'c_update_user' => 'test',
        ]);
        $roomTable->saveOrFail($deleted);

        $result = $this->service->getRoomsByIds($roomTable, [1, 88]);

        $this->assertArrayHasKey(1, $result);
        $this->assertArrayNotHasKey(88, $result);
    }

    public function testGetRoomsByIdsReturnsOnlyMatchingIds(): void
    {
        $roomTable = $this->getTableLocator()->get('MRoomInfo');

        // 存在するIDと存在しないIDの混合
        $result = $this->service->getRoomsByIds($roomTable, [1, 999]);

        $this->assertCount(1, $result);
        $this->assertArrayHasKey(1, $result);
    }
}
