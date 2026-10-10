<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Exception\ApprovedReservationException;
use App\Service\ReservationChangeEditService;
use Cake\Datasource\ConnectionManager;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * ReservationChangeEditService テスト。
 *
 * resolveDefaultRoomId のガード条件と buildUsersForJson の権限別挙動を検証する。
 */
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
            public function get(string $key): int|null
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

    // ----------------------------------------------------------------
    // processUpdate — 承認済み保護（バグ1）
    // ----------------------------------------------------------------

    /**
     * バグ1: 直前編集（processUpdate）も承認済みレコードを変更できない。
     */
    public function testProcessUpdate_approvedReservation_throwsAndKeepsRow(): void
    {
        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $userTable        = TableRegistry::getTableLocator()->get('MUserInfo');

        ConnectionManager::get('test')->insert('t_individual_reservation_info', [
            'i_id_user'          => 3,
            'd_reservation_date' => '2026-06-10',
            'i_reservation_type' => 1,
            'i_id_room'          => 1,
            'eat_flag'           => 1,
            'i_change_flag'      => 1,
            'i_approval_status'  => 2,
            'i_version'          => 1,
            'dt_create'          => '2026-06-01 00:00:00',
            'c_create_user'      => 'test',
        ]);

        $this->expectException(ApprovedReservationException::class);

        try {
            $this->service->processUpdate(
                [3 => [1 => ['i_change_flag' => 0]]],
                [3],
                '2026-06-10',
                1,
                null,
                $reservationTable,
                $userTable,
                true // isRoomManager
            );
        } finally {
            $row = $reservationTable->find()
                ->where([
                    'i_id_user' => 3,
                    'd_reservation_date' => '2026-06-10',
                    'i_reservation_type' => 1,
                    'i_id_room' => 1,
                ])
                ->first();
            $this->assertSame(1, (int)$row->i_change_flag, '承認済みの予約が変更されている');
        }
    }
}
