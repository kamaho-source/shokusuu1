<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Exception\ApprovedReservationException;
use App\Service\ReservationChangeEditService;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\Date;
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

    public function testBuildUsersForJson_level7_isStaffTrue(): void
    {
        $users = [
            ['id' => 1, 'name' => '職員(7)', 'i_user_level' => 7],
        ];

        $result = $this->service->buildUsersForJson($users, null, false, false);

        $this->assertTrue($result[0]['isStaff']);
    }

    public function testBuildUsersForJson_nullUserLevel_isStaffFalse(): void
    {
        $users = [
            ['id' => 1, 'name' => '役職未設定', 'i_user_level' => null],
        ];

        $result = $this->service->buildUsersForJson($users, null, false, false);

        $this->assertFalse($result[0]['isStaff']);
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

    // ----------------------------------------------------------------
    // processUpdate — 同じ食事を複数の部屋で同時に有効にしない（部屋の移動として扱う）
    // ----------------------------------------------------------------

    /** 直前期間（当日〜14日先）の日付 */
    private function nearDate(): string
    {
        return Date::today()->addDays(3)->format('Y-m-d');
    }

    private function insertMeal(int $roomId, int $eat, int $chg, ?string $date = null): void
    {
        ConnectionManager::get('test')->insert('t_individual_reservation_info', [
            'i_id_user'          => 3,
            'd_reservation_date' => $date ?? $this->nearDate(),
            'i_reservation_type' => 1,
            'i_id_room'          => $roomId,
            'eat_flag'           => $eat,
            'i_change_flag'      => $chg,
            'i_approval_status'  => 0,
            'i_version'          => 1,
            'dt_create'          => '2026-01-01 00:00:00',
            'c_create_user'      => 'test',
        ]);
    }

    private function eatInRoom(int $roomId, ?string $date = null): void
    {
        $this->service->processUpdate(
            [3 => [1 => ['i_change_flag' => 1]]],
            [3],
            $date ?? $this->nearDate(),
            $roomId,
            null,
            TableRegistry::getTableLocator()->get('TIndividualReservationInfo'),
            TableRegistry::getTableLocator()->get('MUserInfo'),
            true
        );
    }

    /** @return array<int, array{int, int}> [roomId => [eat_flag, i_change_flag]] */
    private function flagsByRoom(?string $date = null): array
    {
        $rows = TableRegistry::getTableLocator()->get('TIndividualReservationInfo')->find()
            ->where(['i_id_user' => 3, 'd_reservation_date' => $date ?? $this->nearDate(), 'i_reservation_type' => 1])
            ->orderByAsc('i_id_room')
            ->all();
        $map = [];
        foreach ($rows as $r) {
            $map[(int)$r->i_id_room] = [(int)$r->eat_flag, (int)$r->i_change_flag];
        }

        return $map;
    }

    /** 部屋Aが有効（eat0/chg1）のまま部屋Bの行（当初予定あり・直前取消 1/0）を「食べる」にすると、Aが取り消される */
    public function testProcessUpdate_eatInOtherRoom_cancelsEffectiveRoom(): void
    {
        $this->insertMeal(1, 0, 1);
        $this->insertMeal(2, 1, 0);

        $this->eatInRoom(2);

        $this->assertSame([1 => [0, 0], 2 => [1, 1]], $this->flagsByRoom());
    }

    /** 部屋Bに取消済み行（0/0）があっても「食べる」にでき、部屋Aが取り消される */
    public function testProcessUpdate_eatInRoomWithCancelledRow_cancelsEffectiveRoom(): void
    {
        $this->insertMeal(1, 0, 1);
        $this->insertMeal(2, 0, 0);

        $this->eatInRoom(2);

        $this->assertSame([1 => [0, 0], 2 => [0, 1]], $this->flagsByRoom());
    }

    /** 行を書き換えて移すときは、取消済み行（部屋1）ではなく有効な行（部屋2）を移す */
    public function testProcessUpdate_movesEffectiveRowRatherThanCancelledRow(): void
    {
        $this->insertMeal(1, 0, 0);
        $this->insertMeal(2, 0, 1);

        $this->eatInRoom(4);

        $this->assertSame([1 => [0, 0], 4 => [0, 1]], $this->flagsByRoom());
    }

    /** 直前期間外は eat_flag が有効値。部屋を移しても、どの部屋でも食べない状態にしない */
    public function testProcessUpdate_farDate_movingKeepsTheReservation(): void
    {
        $far = \Cake\I18n\Date::today()->addDays(30)->format('Y-m-d');
        $this->insertMeal(1, 1, 1, $far);
        $this->insertMeal(2, 0, 0, $far);

        $this->eatInRoom(2, $far);

        $this->assertSame([1 => [0, 0], 2 => [1, 1]], $this->flagsByRoom($far));
    }

    /** 職員（user 2）は直前期間に部屋を移せない（change-room API と同じ扱い）。部屋1の予約が残り、部屋2には作らない */
    public function testProcessUpdate_staffLastMinute_cannotMoveRoom(): void
    {
        $date = $this->nearDate();
        ConnectionManager::get('test')->insert('t_individual_reservation_info', [
            'i_id_user' => 2, 'd_reservation_date' => $date, 'i_reservation_type' => 1, 'i_id_room' => 1,
            'eat_flag' => 0, 'i_change_flag' => 1, 'i_approval_status' => 0, 'i_version' => 1,
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 'test',
        ]);
        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');

        $this->service->processUpdate(
            [2 => [1 => ['i_change_flag' => 1]]],
            [2],
            $date,
            2,
            null,
            $reservationTable,
            TableRegistry::getTableLocator()->get('MUserInfo'),
            true
        );

        $rows = $reservationTable->find()
            ->where(['i_id_user' => 2, 'd_reservation_date' => $date, 'i_reservation_type' => 1])
            ->all()
            ->combine('i_id_room', fn($r) => [(int)$r->eat_flag, (int)$r->i_change_flag])
            ->toArray();
        $this->assertSame([1 => [0, 1]], $rows);
    }
}
