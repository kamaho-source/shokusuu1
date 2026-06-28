<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ReservationChangeEditService;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

class ReservationChangeEditServiceTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TIndividualReservationInfo',
        'app.TAuditLog',
    ];

    private ReservationChangeEditService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ReservationChangeEditService();
    }

    // ----------------------------------------------------------------
    // resolveDefaultRoomId — DB不要のガード条件
    // ----------------------------------------------------------------

    public function testResolveDefaultRoomId_emptyRooms_returnsNull(): void
    {
        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $result = $this->service->resolveDefaultRoomId([], '2099-01-01', $reservationTable);
        $this->assertNull($result);
    }

    public function testResolveDefaultRoomId_singleRoom_returnsFirstKey(): void
    {
        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $result = $this->service->resolveDefaultRoomId([5 => 'ルームA'], '2099-01-01', $reservationTable);
        $this->assertSame(5, $result);
    }

    public function testResolveDefaultRoomId_multipleRoomsNoReservation_returnsFirstKey(): void
    {
        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $result = $this->service->resolveDefaultRoomId(
            [10 => 'ルームA', 20 => 'ルームB'],
            '2099-12-31',
            $reservationTable
        );
        $this->assertSame(10, $result);
    }

    // ----------------------------------------------------------------
    // buildUsersForJson — DB不要
    // ----------------------------------------------------------------

    public function testBuildUsersForJson_emptyUsers_returnsEmpty(): void
    {
        $result = $this->service->buildUsersForJson([], null, false, false);
        $this->assertSame([], $result);
    }

    public function testBuildUsersForJson_adminCanEditAll(): void
    {
        // i_admin=1 のユーザーはすべてを編集できる
        $loginUser = new class {
            public function get(string $key): mixed
            {
                return match ($key) {
                    'i_admin'      => 1,
                    'i_user_level' => 0,
                    'i_id_user'    => 99,
                    default        => null,
                };
            }
        };

        $users = [
            ['id' => 1, 'name' => 'テスト', 'i_user_level' => 1],
        ];

        $result = $this->service->buildUsersForJson($users, $loginUser, false, false);

        $this->assertCount(1, $result);
        $this->assertTrue($result[0]['allowEdit']);
    }

    public function testBuildUsersForJson_returnsRequiredKeys(): void
    {
        $users = [
            ['id' => 1, 'name' => 'テスト', 'i_user_level' => 1],
        ];

        $result = $this->service->buildUsersForJson($users, null, false, false);

        $this->assertArrayHasKey('id', $result[0]);
        $this->assertArrayHasKey('name', $result[0]);
        $this->assertArrayHasKey('i_user_level', $result[0]);
        $this->assertArrayHasKey('allowEdit', $result[0]);
        $this->assertArrayHasKey('isStaff', $result[0]);
    }

    public function testBuildUsersForJson_staffUserLevel_isStaffTrue(): void
    {
        $users = [
            ['id' => 1, 'name' => '職員', 'i_user_level' => 0],
        ];

        $result = $this->service->buildUsersForJson($users, null, false, false);

        $this->assertTrue($result[0]['isStaff']);
    }

    public function testBuildUsersForJson_childUserLevel_isStaffFalse(): void
    {
        $users = [
            ['id' => 2, 'name' => '子供', 'i_user_level' => 1],
        ];

        $result = $this->service->buildUsersForJson($users, null, false, false);

        $this->assertFalse($result[0]['isStaff']);
    }

    public function testBuildUsersForJson_roomManager_canEditAll(): void
    {
        $users = [
            ['id' => 5, 'name' => '他者', 'i_user_level' => 0],
        ];

        $result = $this->service->buildUsersForJson($users, null, isRoomManager: true, isBlockLeaderInRoom: false);

        $this->assertTrue($result[0]['allowEdit']);
    }
}
