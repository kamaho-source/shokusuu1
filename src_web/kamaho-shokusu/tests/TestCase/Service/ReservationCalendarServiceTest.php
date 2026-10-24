<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ReservationCalendarService;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\Date;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * ReservationCalendarService テスト。
 *
 * getPrimaryRoomId・buildMyReservationDates・buildCalendarEvents・getUserRoomIds・isOfficeUser・getRoomsForUser の挙動を検証する。
 */
class ReservationCalendarServiceTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TIndividualReservationInfo',
    ];

    private ReservationCalendarService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ReservationCalendarService();
    }

    // ----------------------------------------------------------------
    // getPrimaryRoomId — DB不要
    // ----------------------------------------------------------------

    public function testGetPrimaryRoomId_emptyArray_returnsNull(): void
    {
        $this->assertNull($this->service->getPrimaryRoomId([]));
    }

    public function testGetPrimaryRoomId_returnsFirstElement(): void
    {
        $this->assertSame(42, $this->service->getPrimaryRoomId([42, 10, 5]));
    }

    // ----------------------------------------------------------------
    // buildMyReservationDates — DB不要
    // ----------------------------------------------------------------

    public function testBuildMyReservationDates_emptyDetails_returnsEmpty(): void
    {
        $this->assertSame([], $this->service->buildMyReservationDates([]));
    }

    public function testBuildMyReservationDates_onlyReservedDatesIncluded(): void
    {
        $details = [
            '2099-01-01' => ['breakfast' => 1, 'lunch' => null, 'dinner' => null, 'bento' => null],
            '2099-01-02' => ['breakfast' => 0, 'lunch' => 0, 'dinner' => 0, 'bento' => 0],
            '2099-01-03' => ['breakfast' => null, 'lunch' => null, 'dinner' => null, 'bento' => null],
        ];

        $result = $this->service->buildMyReservationDates($details);

        $this->assertSame(['2099-01-01'], $result);
    }

    public function testBuildMyReservationDates_sortsDates(): void
    {
        $details = [
            '2099-03-01' => ['breakfast' => 1, 'lunch' => null, 'dinner' => null, 'bento' => null],
            '2099-01-01' => ['breakfast' => 1, 'lunch' => null, 'dinner' => null, 'bento' => null],
            '2099-02-01' => ['breakfast' => 1, 'lunch' => null, 'dinner' => null, 'bento' => null],
        ];

        $result = $this->service->buildMyReservationDates($details);

        $this->assertSame(['2099-01-01', '2099-02-01', '2099-03-01'], $result);
    }

    // ----------------------------------------------------------------
    // buildCalendarEvents — DB不要
    // ----------------------------------------------------------------

    public function testBuildCalendarEvents_noReservations_generatesUnreservedEvents(): void
    {
        $startDate = new Date('2099-01-01');
        $endDate   = new Date('2099-01-04');

        $events = $this->service->buildCalendarEvents([], [], $startDate, $endDate);

        // 予約なしの場合、日付ごとに「未予約」イベントが生成されること
        $titles = array_column($events, 'title');
        $this->assertContains('未予約', $titles);
    }

    public function testBuildCalendarEvents_withReservation_addsReservedEvent(): void
    {
        $startDate = new Date('2099-01-01');
        $endDate   = new Date('2099-01-03');
        $details   = [
            '2099-01-01' => ['breakfast' => 1, 'lunch' => null, 'dinner' => null, 'bento' => null],
        ];

        $events = $this->service->buildCalendarEvents([], $details, $startDate, $endDate);

        $starts = array_column($events, 'start');
        $this->assertContains('2099-01-01', $starts);
    }

    // ----------------------------------------------------------------
    // getUserRoomIds — DB使用
    // ----------------------------------------------------------------

    public function testGetUserRoomIds_existingUser_returnsRoomIds(): void
    {
        $userGroupTable = TableRegistry::getTableLocator()->get('MUserGroup');

        $result = $this->service->getUserRoomIds($userGroupTable, 1);

        $this->assertIsArray($result);
        $this->assertContains(1, $result);
    }

    public function testGetUserRoomIds_nonExistentUser_returnsEmpty(): void
    {
        $userGroupTable = TableRegistry::getTableLocator()->get('MUserGroup');

        $result = $this->service->getUserRoomIds($userGroupTable, 9999);

        $this->assertSame([], $result);
    }

    // ----------------------------------------------------------------
    // isOfficeUser — DB使用
    // ----------------------------------------------------------------

    public function testIsOfficeUser_zeroUserId_returnsFalse(): void
    {
        $userGroupTable = TableRegistry::getTableLocator()->get('MUserGroup');
        $roomTable      = TableRegistry::getTableLocator()->get('MRoomInfo');

        $this->assertFalse($this->service->isOfficeUser($userGroupTable, $roomTable, 0));
    }

    public function testIsOfficeUser_noOfficeRoom_returnsFalse(): void
    {
        $userGroupTable = TableRegistry::getTableLocator()->get('MUserGroup');
        $roomTable      = TableRegistry::getTableLocator()->get('MRoomInfo');

        // フィクスチャの部屋名は '事務所' を含まないので false が返る
        $this->assertFalse($this->service->isOfficeUser($userGroupTable, $roomTable, 1));
    }

    // ----------------------------------------------------------------
    // getRoomsForUser — DB使用
    // ----------------------------------------------------------------

    public function testGetRoomsForUser_includeAllRoomsReturnsAllRooms(): void
    {
        $roomTable = TableRegistry::getTableLocator()->get('MRoomInfo');

        $result = $this->service->getRoomsForUser($roomTable, [], includeAllRooms: true);

        $this->assertIsArray($result);
        $this->assertArrayHasKey(1, $result); // fixture にid=1の部屋が存在する
    }

    public function testGetRoomsForUser_emptyRoomIds_returnsEmpty(): void
    {
        $roomTable = TableRegistry::getTableLocator()->get('MRoomInfo');

        $result = $this->service->getRoomsForUser($roomTable, [], includeAllRooms: false);

        $this->assertSame([], $result);
    }

    /** 複数部屋所属のブロック長には所属している全部屋を返す */
    public function testGetRoomsForUser_blockLeaderReturnsAllBelongingRooms(): void
    {
        $this->insertRoom(2, '第二の部屋', 0);
        $roomTable = TableRegistry::getTableLocator()->get('MRoomInfo');

        $result = $this->service->getRoomsForUser($roomTable, [1, 2], includeAllRooms: false, isOfficeUser: false, isBlockLeader: true);

        $this->assertSame([1, 2], array_keys($result));
    }

    /** ブロック長でも削除済みの部屋は返さない */
    public function testGetRoomsForUser_blockLeaderExcludesDeletedRooms(): void
    {
        $this->insertRoom(3, '削除済みの部屋', 1);
        $roomTable = TableRegistry::getTableLocator()->get('MRoomInfo');

        $result = $this->service->getRoomsForUser($roomTable, [1, 3], includeAllRooms: false, isOfficeUser: false, isBlockLeader: true);

        $this->assertSame([1], array_keys($result));
    }

    /** ブロック長以外（職員など）も、複数部屋に所属していれば全部屋を返す */
    public function testGetRoomsForUser_regularUserReturnsAllBelongingRooms(): void
    {
        $this->insertRoom(2, '第二の部屋', 0);
        $roomTable = TableRegistry::getTableLocator()->get('MRoomInfo');

        $result = $this->service->getRoomsForUser($roomTable, [1, 2], includeAllRooms: false);

        $this->assertSame([1, 2], array_keys($result));
    }

    /** 所属していない部屋は返さない */
    public function testGetRoomsForUser_regularUserDoesNotReturnOtherRooms(): void
    {
        $this->insertRoom(2, '第二の部屋', 0);
        $this->insertRoom(3, '第三の部屋', 0);
        $roomTable = TableRegistry::getTableLocator()->get('MRoomInfo');

        $result = $this->service->getRoomsForUser($roomTable, [1, 2], includeAllRooms: false);

        $this->assertSame([1, 2], array_keys($result));
    }

    private function insertRoom(int $roomId, string $roomName, int $delFlg): void
    {
        ConnectionManager::get('test')->insert('m_room_info', [
            'i_id_room'   => $roomId,
            'c_room_name' => $roomName,
            'i_disp_no'   => $roomId,
            'i_enable'    => 1,
            'i_del_flg'   => $delFlg,
        ]);
    }

    public function testBuildMyReservationDetails_cancelledRowInOldRoomDoesNotHideMovedReservation(): void
    {
        // 部屋変更の後は、移動先の有効な行と、元の部屋の取消済みの行が並ぶ
        $date = Date::today()->addDays(40)->format('Y-m-d');
        $db = ConnectionManager::get('test');
        foreach ([[3, 1], [9, 0]] as [$roomId, $flag]) {
            $db->insert('t_individual_reservation_info', [
                'i_id_user' => 9902, 'd_reservation_date' => $date, 'i_reservation_type' => 1, 'i_id_room' => $roomId,
                'eat_flag' => $flag, 'i_change_flag' => $flag, 'i_version' => 1,
                'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't', 'dt_update' => '2026-01-01 00:00:00', 'c_update_user' => 't',
            ]);
        }

        $details = $this->service->buildMyReservationDetails(TableRegistry::getTableLocator()->get('TIndividualReservationInfo'), 9902);

        $this->assertSame(1, $details[$date]['breakfast']);
        $this->assertSame(3, $details[$date]['breakfastRoom']);
    }

    public function testBuildMyReservationDetails_cancelledRowFirstThenEffectiveRow(): void
    {
        $date = Date::today()->addDays(41)->format('Y-m-d');
        $db = ConnectionManager::get('test');
        foreach ([[2, 0], [8, 1]] as [$roomId, $flag]) {
            $db->insert('t_individual_reservation_info', [
                'i_id_user' => 9903, 'd_reservation_date' => $date, 'i_reservation_type' => 3, 'i_id_room' => $roomId,
                'eat_flag' => $flag, 'i_change_flag' => $flag, 'i_version' => 1,
                'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't', 'dt_update' => '2026-01-01 00:00:00', 'c_update_user' => 't',
            ]);
        }

        $details = $this->service->buildMyReservationDetails(TableRegistry::getTableLocator()->get('TIndividualReservationInfo'), 9903);

        $this->assertSame(1, $details[$date]['dinner']);
        $this->assertSame(8, $details[$date]['dinnerRoom']);
    }

    /** 直前期間外は eat_flag が有効値（集計・予約の書き込みと同じ基準）。予約コピーで eat1/chg0 の行ができても予約ありと表示する */
    public function testBuildMyReservationDetails_farDateUsesEatFlag(): void
    {
        $date = Date::today()->addDays(42)->format('Y-m-d');
        ConnectionManager::get('test')->insert('t_individual_reservation_info', [
            'i_id_user' => 9904, 'd_reservation_date' => $date, 'i_reservation_type' => 2, 'i_id_room' => 5,
            'eat_flag' => 1, 'i_change_flag' => 0, 'i_version' => 1,
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't', 'dt_update' => '2026-01-01 00:00:00', 'c_update_user' => 't',
        ]);

        $details = $this->service->buildMyReservationDetails(TableRegistry::getTableLocator()->get('TIndividualReservationInfo'), 9904);

        $this->assertSame(1, $details[$date]['lunch']);
        $this->assertSame(5, $details[$date]['lunchRoom']);
    }

    /** 過ぎた日は、食数の報告と同じく i_change_flag で判定する（直前期間に追加した予約 eat0/chg1 を消さない） */
    public function testBuildMyReservationDetails_pastDateUsesChangeFlag(): void
    {
        $date = Date::today()->subDays(3)->format('Y-m-d');
        ConnectionManager::get('test')->insert('t_individual_reservation_info', [
            'i_id_user' => 9905, 'd_reservation_date' => $date, 'i_reservation_type' => 3, 'i_id_room' => 5,
            'eat_flag' => 0, 'i_change_flag' => 1, 'i_version' => 1,
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't', 'dt_update' => '2026-01-01 00:00:00', 'c_update_user' => 't',
        ]);
        $table = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');

        $details = $this->service->buildMyReservationDetails($table, 9905);
        $counts = $this->service->buildMealCountsByDate($table, [5]);

        $this->assertSame(1, $details[$date]['dinner']);
        $this->assertSame(1, $counts[$date][3]);
    }

    /** 境界日（今日+14日）までは i_change_flag、その翌日からは eat_flag で数える（食数の報告と同じ） */
    public function testBuildMealCountsByDate_boundaryDay(): void
    {
        $d14 = Date::today()->addDays(14)->format('Y-m-d');
        $d15 = Date::today()->addDays(15)->format('Y-m-d');
        foreach ([$d14, $d15] as $date) {
            ConnectionManager::get('test')->insert('t_individual_reservation_info', [
                'i_id_user' => 9906, 'd_reservation_date' => $date, 'i_reservation_type' => 1, 'i_id_room' => 6,
                'eat_flag' => 1, 'i_change_flag' => 0, 'i_version' => 1,
                'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't', 'dt_update' => '2026-01-01 00:00:00', 'c_update_user' => 't',
            ]);
        }

        $counts = $this->service->buildMealCountsByDate(TableRegistry::getTableLocator()->get('TIndividualReservationInfo'), [6]);

        $this->assertArrayNotHasKey($d14, $counts, '境界日は i_change_flag=0 で数えない');
        $this->assertSame(1, $counts[$d15][1], '境界日の翌日は eat_flag=1 で数える');
    }

    /** 削除済みの部屋は所属に数えない（代表部屋が削除済みになり、カレンダーで予約を開けなくなっていた） */
    public function testGetUserRoomIds_excludesDeletedRooms(): void
    {
        $conn = ConnectionManager::get('test');
        $conn->insert('m_room_info', ['i_id_room' => 7, 'c_room_name' => '削除済み', 'i_disp_no' => 7, 'i_enable' => 1, 'i_del_flg' => 1, 'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't']);
        $conn->insert('m_user_group', ['i_id_user' => 3, 'i_id_room' => 7, 'active_flag' => 0, 'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't']);

        $ids = $this->service->getUserRoomIds(TableRegistry::getTableLocator()->get('MUserGroup'), 3);

        $this->assertNotContains(7, $ids);
        $this->assertContains(1, $ids);
    }

    /** 全部屋（事務所ユーザー用）でも、削除済みの部屋は選択肢に出さない */
    public function testGetRoomsForUser_includeAllRoomsExcludesDeletedRooms(): void
    {
        $this->insertRoom(75, '削除済み', 1);
        $roomTable = TableRegistry::getTableLocator()->get('MRoomInfo');

        $result = $this->service->getRoomsForUser($roomTable, [], includeAllRooms: true);

        $this->assertArrayHasKey(1, $result);
        $this->assertArrayNotHasKey(75, $result);
    }

    private function addMember(int $userId, int $roomId): void
    {
        ConnectionManager::get('test')->insert('m_user_group', [
            'i_id_user' => $userId, 'i_id_room' => $roomId, 'active_flag' => 0,
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't', 'dt_update' => '2026-01-01 00:00:00', 'c_update_user' => 't',
        ]);
    }

    /** 削除済みの事務所にしか所属がない場合は、事務所ユーザーにしない（全部屋の集計を見せない） */
    public function testIsOfficeUser_deletedOfficeRoomDoesNotCount(): void
    {
        $this->insertRoom(76, '旧事務所', 1);
        $this->addMember(2, 76);
        $userGroupTable = TableRegistry::getTableLocator()->get('MUserGroup');
        $roomTable      = TableRegistry::getTableLocator()->get('MRoomInfo');

        $this->assertFalse($this->service->isOfficeUser($userGroupTable, $roomTable, 2));

        $this->insertRoom(77, '事務所', 0);
        $this->addMember(2, 77);
        $this->assertTrue($this->service->isOfficeUser($userGroupTable, $roomTable, 2));
    }

    /** 事務所ユーザーの部屋の選択肢から、削除済みの事務所を除く */
    public function testGetRoomsForUser_officeExcludesDeletedRooms(): void
    {
        $this->insertRoom(76, '旧事務所', 1);
        $this->insertRoom(77, '事務所', 0);
        $roomTable = TableRegistry::getTableLocator()->get('MRoomInfo');

        $result = $this->service->getRoomsForUser($roomTable, [76, 77], includeAllRooms: false, isOfficeUser: true);

        $this->assertSame([77], array_keys($result));
    }

    /** buildTotalEvents: 部屋の指定が空なら0件（全部屋に落ちない）。null は全部屋、指定ありはその部屋だけ */
    public function testBuildTotalEvents_roomFilter(): void
    {
        $date = '2026-11-20';
        foreach ([[1, 1], [2, 2]] as [$userId, $roomId]) {
            ConnectionManager::get('test')->insert('t_individual_reservation_info', [
                'd_reservation_date' => $date, 'i_id_user' => $userId, 'i_reservation_type' => 2, 'i_id_room' => $roomId,
                'eat_flag' => 1, 'i_change_flag' => 1, 'i_version' => 1, 'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't',
            ]);
        }
        $table = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $total = function (?array $roomIds) use ($table, $date): string {
            $titles = array_column($this->service->buildTotalEvents($table, $date, '2026-11-21', null, $roomIds), 'title');

            return implode(',', $titles);
        };

        $this->assertSame('', $total([]));
        $this->assertSame('合計食数: 1', $total([1]));
        $this->assertStringContainsString('合計食数: ', $total(null));
        $this->assertNotSame('合計食数: 1', $total(null), '全部屋は部屋1だけより多い');
    }
}
