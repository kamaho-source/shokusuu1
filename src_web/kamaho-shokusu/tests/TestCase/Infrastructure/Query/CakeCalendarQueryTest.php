<?php
declare(strict_types=1);

namespace App\Test\TestCase\Infrastructure\Query;

use App\Application\Query\CalendarEventBuilder;
use App\Application\Query\Dto\DayMealCounts;
use App\Application\Query\Dto\MyReservationDay;
use App\Infrastructure\Query\CakeCalendarQuery;
use App\Infrastructure\Query\CakeRoomQuery;
use Cake\Chronos\ClockFactory;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\Date;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * 予約カレンダーの読み取り（CakeCalendarQuery）とイベントの組み立て（CalendarEventBuilder）。旧 ReservationCalendarServiceTest を引き継ぐ。
 *
 * 旧 Service と同じ呼び出しで書いたテストの意味を変えないよう、adapter() で新しい部品につなぐ。
 */
class CakeCalendarQueryTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TIndividualReservationInfo',
    ];

    private object $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = self::adapter();
    }

    /** 旧 ReservationCalendarService と同じ呼び出しを、CakeCalendarQuery・CakeRoomQuery・CalendarEventBuilder につなぐ */
    private static function adapter(): object
    {
        return new class {
            private CakeCalendarQuery $calendar;
            private CakeRoomQuery $rooms;

            public function __construct()
            {
                $this->calendar = new CakeCalendarQuery(new ClockFactory('Asia/Tokyo'));
                $this->rooms    = new CakeRoomQuery();
            }

            /** @param array<string, array<string, int|null>> $details */
            public function buildMyReservationDates(array $details): array
            {
                return CalendarEventBuilder::reservedDates(self::days($details));
            }

            /**
             * @param array<string, array<int, int>> $meals
             * @param array<string, array<string, int|null>> $details
             */
            public function buildCalendarEvents(array $meals, array $details, \Cake\Chronos\ChronosDate $start, \Cake\Chronos\ChronosDate $end): array
            {
                $counts = [];
                foreach ($meals as $date => $byMeal) {
                    $counts[$date] = new DayMealCounts((string)$date, $byMeal);
                }

                return CalendarEventBuilder::calendarEvents($counts, self::days($details), $start, $end);
            }

            public function getUserRoomIds(object $table, int $userId): array
            {
                return $this->rooms->getUserRoomIds($userId);
            }

            public function isOfficeUser(object $userGroupTable, object $roomTable, int $userId): bool
            {
                return $this->rooms->isOfficeUser($userId);
            }

            /** @param list<int> $roomIds */
            public function getRoomsForUser(object $table, array $roomIds, bool $includeAllRooms, bool $isOfficeUser = false): array
            {
                return $this->calendar->getRoomsForUser($roomIds, $includeAllRooms, $isOfficeUser);
            }

            public function buildMyReservationDetails(object $table, int $userId): array
            {
                return array_map(static fn(MyReservationDay $d) => $d->toArray(), $this->calendar->getMyReservationDays($userId, null, null));
            }

            /** @param list<int>|null $roomIds */
            public function buildMealCountsByDate(object $table, ?array $roomIds = null): array
            {
                return array_map(static fn(DayMealCounts $d) => $d->toArray(), $this->calendar->getMealCountsByDate($roomIds, null, null));
            }

            /** @param list<int>|null $roomIds */
            public function buildTotalEvents(object $table, string $start, string $end, ?object $border = null, ?array $roomIds = null): array
            {
                return CalendarEventBuilder::totalEvents($this->calendar->getTotalCountsByDate($start, $end, $roomIds));
            }

            /**
             * @param array<string, array<string, int|null>> $details
             * @return array<string, MyReservationDay>
             */
            private static function days(array $details): array
            {
                $keys = ['breakfast', 'breakfastRoom', 'lunch', 'lunchRoom', 'dinner', 'dinnerRoom', 'bento', 'bentoRoom'];

                return array_map(static fn(array $d) => new MyReservationDay(...array_intersect_key($d, array_flip($keys))), $details);
            }
        };
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

    /** 削除済みの部屋は返さない */
    public function testGetRoomsForUser_excludesDeletedRooms(): void
    {
        $this->insertRoom(3, '削除済みの部屋', 1);
        $roomTable = TableRegistry::getTableLocator()->get('MRoomInfo');

        $result = $this->service->getRoomsForUser($roomTable, [1, 3], includeAllRooms: false);

        $this->assertSame([1], array_keys($result));
    }

    /** 複数部屋に所属していれば全部屋を返す */
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
        DateTime::setTestNow(new DateTime('2026-11-01 23:30:00', 'Asia/Tokyo'));
        try {
            $this->assertBoundaryDay('2026-11-15', '2026-11-16');
        } finally {
            DateTime::setTestNow(null);
        }
    }

    private function assertBoundaryDay(string $d14, string $d15): void
    {
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

    /** 食事区分（1〜4）以外の行は、日ごとの食数に数えない（警告も出さない） */
    public function testMealCountsByDate_ignoresUnknownMealType(): void
    {
        $date = Date::today()->addDays(40)->format('Y-m-d');
        $insert = fn(int $type) => ConnectionManager::get('test')->insert('t_individual_reservation_info', [
            'i_id_user' => 1, 'd_reservation_date' => $date, 'i_reservation_type' => $type, 'i_id_room' => 1,
            'eat_flag' => 1, 'i_change_flag' => 1, 'i_version' => 1, 'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't',
        ]);
        $insert(1);
        $insert(5);

        $counts = (new CakeCalendarQuery(new ClockFactory('Asia/Tokyo')))->getMealCountsByDate([1], $date, Date::today()->addDays(41)->format('Y-m-d'));

        $this->assertSame([1 => 1, 2 => 0, 3 => 0, 4 => 0], $counts[$date]->counts);
    }

    /** 事務所ユーザーの部屋の選択肢は、所属部屋のうち「事務所」の部屋だけ */
    public function testRoomsForOfficeUser_onlyOfficeRooms(): void
    {
        $db = ConnectionManager::get('test');
        $db->insert('m_room_info', ['i_id_room' => 80, 'c_room_name' => '本部事務所', 'i_disp_no' => 80, 'i_del_flg' => 0]);

        $rooms = (new CakeCalendarQuery(new ClockFactory('Asia/Tokyo')))->getRoomsForUser([1, 80], false, true);

        $this->assertSame([80 => '本部事務所'], $rooms);
    }

    /** 食べない予約には部屋を付けない（食べる予約だけ部屋を持つ） */
    public function testMyReservationDays_roomOnlyWhenEating(): void
    {
        $date = Date::today()->addDays(40)->format('Y-m-d');
        $insert = fn(int $type, int $eat) => ConnectionManager::get('test')->insert('t_individual_reservation_info', [
            'i_id_user' => 1, 'd_reservation_date' => $date, 'i_reservation_type' => $type, 'i_id_room' => 1,
            'eat_flag' => $eat, 'i_change_flag' => $eat, 'i_version' => 1, 'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't',
        ]);
        $insert(1, 0);
        $insert(2, 1);

        $day = (new CakeCalendarQuery(new ClockFactory('Asia/Tokyo')))->getMyReservationDays(1, $date, null)[$date];

        $this->assertSame([0, null, 1, 1], [$day->breakfast, $day->breakfastRoom, $day->lunch, $day->lunchRoom]);
    }

    /** 一覧の部屋の範囲：所属外の部屋を指定しても既定の部屋になり、事務所ユーザーでなければ集計はその部屋だけ */
    public function testRoomScope_unknownRequestedRoomFallsBackToDefault(): void
    {
        $db = ConnectionManager::get('test');
        $db->insert('m_room_info', ['i_id_room' => 81, 'c_room_name' => '削除済み', 'i_disp_no' => 81, 'i_del_flg' => 1]);

        $scope = (new CakeCalendarQuery(new ClockFactory('Asia/Tokyo')))->roomScope([81, 1], false, 999);

        $this->assertSame([[1], 1, 1, [1]], [$scope->userRoomIds, $scope->userRoomId, $scope->calRoomId, $scope->roomFilter]);
        $this->assertSame($scope->pickerRooms, $scope->rooms);
    }

    /** 集計範囲が空なら、全部屋の集計に落ちないよう該当なしの ID（0）にする */
    public function testCountableRoomIds_emptyIsZero(): void
    {
        $this->assertSame([0], (new CakeCalendarQuery(new ClockFactory('Asia/Tokyo')))->countableRoomIds(false, []));
    }

    /** 初期表示の期間は前月の月初〜翌々月末の翌日（終わりは含まない） */
    public function testInitialViewRange(): void
    {
        \Cake\Chronos\Chronos::setTestNow('2026-11-17 09:00:00');
        try {
            $this->assertSame(['2026-10-01', '2027-02-01'], (new CakeCalendarQuery(new ClockFactory('Asia/Tokyo')))->initialViewRange());
        } finally {
            \Cake\Chronos\Chronos::setTestNow(null);
        }
    }
}
