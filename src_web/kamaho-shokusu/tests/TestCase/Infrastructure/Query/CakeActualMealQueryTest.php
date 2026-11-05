<?php
declare(strict_types=1);

namespace App\Test\TestCase\Infrastructure\Query;

use App\Infrastructure\Query\CakeActualMealQuery;
use Cake\Chronos\ClockFactory;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * 実食入力の読み取り（大人一覧・週の表）。旧 ActualMealManagementServiceTest の getAdultUsers・buildWeekGrid を引き継ぐ。
 */
class CakeActualMealQueryTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TIndividualReservationInfo',
    ];

    private CakeActualMealQuery $query;

    protected function setUp(): void
    {
        parent::setUp();
        $this->query = new CakeActualMealQuery(new ClockFactory('Asia/Tokyo'));
    }

    private function insertStaffUser(int $userId, string $name, ?string $staffId, int $roomId, int $activeFlag = 0, int $delFlag = 0): void
    {
        $db = ConnectionManager::get('test');
        $db->insert('m_user_info', [
            'i_id_user' => $userId, 'c_login_account' => 'acct' . $userId, 'c_login_passwd' => 'x',
            'c_user_name' => $name, 'i_admin' => 0, 'i_user_level' => 0, 'i_enable' => 0,
            'i_del_flag' => $delFlag, 'i_id_staff' => $staffId,
            'dt_create' => '2024-01-01 00:00:00', 'dt_update' => '2024-01-01 00:00:00',
        ]);
        $db->insert('m_user_group', [
            'i_id_user' => $userId, 'i_id_room' => $roomId, 'active_flag' => $activeFlag,
            'dt_create' => '2024-01-01 00:00:00', 'dt_update' => '2024-01-01 00:00:00',
        ]);
    }

    private function insertReservation(int $userId, string $date, int $type, int $eat, int $change, int $status = 0, int $version = 1, int $roomId = 1): void
    {
        ConnectionManager::get('test')->insert('t_individual_reservation_info', [
            'i_id_user' => $userId, 'd_reservation_date' => $date, 'i_reservation_type' => $type,
            'i_id_room' => $roomId, 'eat_flag' => $eat, 'i_change_flag' => $change,
            'i_approval_status' => $status, 'i_version' => $version,
            'dt_create' => '2024-01-01 00:00:00', 'c_create_user' => 't',
            'dt_update' => '2024-01-01 00:00:00', 'c_update_user' => 't',
        ]);
    }

    // ---- getAdultUsers ----

    public function testGetAdultUsers_returnsArray(): void
    {
        $this->assertIsArray($this->query->getAdultUsers(1));
    }

    public function testGetAdultUsers_returnsExpectedKeys(): void
    {
        // フィクスチャのユーザーは i_id_staff が null なので staff_id 未設定ユーザーは除外され空配列が返る
        $this->assertSame([], $this->query->getAdultUsers(1));
    }

    public function testGetAdultUsers_nonExistentRoom_returnsEmpty(): void
    {
        $this->assertSame([], $this->query->getAdultUsers(9999));
    }

    public function testGetAdultUsers_returnsOnlyActiveStaffInRoomSortedByName(): void
    {
        $this->insertStaffUser(101, 'ブ職員', 'S2', 1);
        $this->insertStaffUser(102, 'ア職員', 'S1', 1);
        $this->insertStaffUser(103, '退出済み', 'S3', 1, 1);
        $this->insertStaffUser(104, '削除済み', 'S4', 1, 0, 1);
        $this->insertStaffUser(105, '空ID', '', 1);
        $this->insertStaffUser(106, '別部屋', 'S6', 2);

        $this->assertSame(
            [
                ['id' => 102, 'name' => 'ア職員', 'staff_id' => 'S1'],
                ['id' => 101, 'name' => 'ブ職員', 'staff_id' => 'S2'],
            ],
            $this->query->getAdultUsers(1)
        );
    }

    // ---- getWeekGrid ----

    public function testGetWeekGrid_returnsRequiredKeys(): void
    {
        $monday = (new \DateTimeImmutable('monday this week'))->format('Y-m-d');

        $result = $this->query->getWeekGrid([], $monday);

        $this->assertArrayHasKey('dates', $result);
        $this->assertArrayHasKey('meals', $result);
        $this->assertArrayHasKey('grid', $result);
        $this->assertArrayHasKey('versions', $result);
        $this->assertArrayHasKey('statuses', $result);
    }

    public function testGetWeekGrid_emptyUsers_returnSevenDates(): void
    {
        $monday = (new \DateTimeImmutable('monday this week'))->format('Y-m-d');

        $result = $this->query->getWeekGrid([], $monday);

        $this->assertCount(7, $result['dates']);
        $this->assertSame([], $result['grid']);
    }

    public function testGetWeekGrid_mealsHasThreeTypes(): void
    {
        $monday = (new \DateTimeImmutable('monday this week'))->format('Y-m-d');

        $result = $this->query->getWeekGrid([], $monday);

        $this->assertArrayHasKey(1, $result['meals']);
        $this->assertArrayHasKey(2, $result['meals']);
        $this->assertArrayHasKey(3, $result['meals']);
    }

    public function testGetWeekGrid_usesChangeFlagForPastAndEatFlagForFarFuture(): void
    {
        // 過去週(2000-01-03 月曜): i_change_flag が実効値。eat=1/change=0 → false
        $this->insertReservation(101, '2000-01-03', 1, 1, 0, 0, 5);
        $this->insertReservation(101, '2000-01-04', 2, 0, 1, 1, 3);
        $users = [['id' => 101, 'name' => 'a', 'staff_id' => 'S']];

        $past = $this->query->getWeekGrid($users, '2000-01-03');

        $this->assertSame(['2000-01-03', '2000-01-04', '2000-01-05', '2000-01-06', '2000-01-07', '2000-01-08', '2000-01-09'], $past['dates']);
        $this->assertFalse($past['grid'][101]['2000-01-03'][1]);
        $this->assertSame(5, $past['versions'][101]['2000-01-03'][1]);
        $this->assertTrue($past['grid'][101]['2000-01-04'][2]);
        $this->assertSame(1, $past['statuses'][101]['2000-01-04'][2]);
        // 予約なしは false / version 1 / status 0
        $this->assertFalse($past['grid'][101]['2000-01-05'][3]);
        $this->assertSame(1, $past['versions'][101]['2000-01-05'][3]);
        $this->assertSame(0, $past['statuses'][101]['2000-01-05'][3]);

        // 遠い未来: eat_flag が実効値。eat=1/change=0 → true
        $this->insertReservation(101, '2099-01-05', 1, 1, 0);
        $future = $this->query->getWeekGrid($users, '2099-01-05');
        $this->assertTrue($future['grid'][101]['2099-01-05'][1]);
    }

    public function testNormalizeDateString_handlesVariousInputs(): void
    {
        $m = new \ReflectionMethod(CakeActualMealQuery::class, 'normalizeDateString');

        $this->assertSame('2026-03-02', $m->invoke(null, new \Cake\I18n\Date('2026-03-02')));
        $this->assertSame('2026-03-02', $m->invoke(null, new \DateTimeImmutable('2026-03-02 10:00:00')));
        $this->assertSame('2026-03-02', $m->invoke(null, '2026-03-02 10:00:00'));
        $this->assertNull($m->invoke(null, ''));
        $this->assertNull($m->invoke(null, 'not a date'));
        $this->assertNull($m->invoke(null, null));
    }
}
