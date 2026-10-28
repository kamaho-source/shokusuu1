<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Cache\Cache;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * ReservationReportController 統合テスト（食数取得・エクスポート・欠食/実食報告 API）
 */
class ReservationReportControllerTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.MRoomInfo',
        'app.MUserInfo',
        'app.MUserGroup',
        'app.TIndividualReservationInfo',
        'app.TReservationInfo',
        'app.TAuditLog',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        Cache::clear('default');
    }

    /** user 1=管理者, 2=職員, 3=児童（いずれも部屋1所属） */
    private function login(int $userId): void
    {
        $rows = [
            1 => ['admin_user', 1, 0],
            2 => ['staff_user', 0, 0],
            3 => ['non_staff_user', 0, 1],
        ];
        [$account, $admin, $level] = $rows[$userId];
        $this->session(['Auth' => [
            'i_id_user' => $userId, 'c_login_account' => $account, 'c_user_name' => 'テスト' . $userId,
            'i_admin' => $admin, 'i_user_level' => $level, 'i_id_room' => 1,
        ]]);
        $this->enableCsrfToken();
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string)$this->_response->getBody(), true);
    }

    private function insertReservation(int $userId, int $type, string $date, int $eat, int $change, int $room = 1): void
    {
        ConnectionManager::get('test')->insert('t_individual_reservation_info', [
            'i_id_user' => $userId, 'd_reservation_date' => $date, 'i_reservation_type' => $type, 'i_id_room' => $room,
            'eat_flag' => $eat, 'i_change_flag' => $change, 'i_version' => 1,
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't',
        ]);
    }

    /** 対象テーブルを一時的に退避して DB 例外を起こす（500 の分岐用） */
    private function getWithBrokenTable(string $url, string $table): void
    {
        $conn = ConnectionManager::get('test');
        $conn->execute("ALTER TABLE {$table} RENAME TO {$table}_bak");
        try {
            $this->get($url);
        } finally {
            $conn->execute("ALTER TABLE {$table}_bak RENAME TO {$table}");
        }
    }

    // ---- getMealCounts ----

    public function testGetMealCounts_staff_returnsCountsPerMealType(): void
    {
        $this->insertReservation(1, 1, '2026-03-01', 0, 1);
        $this->insertReservation(2, 1, '2026-03-01', 0, 1);
        $this->login(1);
        $this->get('/TReservationInfo/getMealCounts/2026-03-01');

        $this->assertResponseOk();
        $json = $this->json();
        $this->assertTrue($json['ok']);
        $byType = array_column($json['data']['mealCounts'], 'count', 'meal_type');
        $this->assertSame(2, $byType[1]);
    }

    public function testGetMealCounts_child_forbidden(): void
    {
        $this->login(3);
        $this->get('/TReservationInfo/getMealCounts/2026-03-01');
        $this->assertResponseCode(403);
        $this->assertFalse($this->json()['ok']);
    }

    // ---- getUsersByRoomForEdit ----

    public function testGetUsersByRoomForEdit_returnsMealStatusPerUser(): void
    {
        $this->insertReservation(1, 3, '2026-03-01', 0, 1);
        $this->login(1);
        $this->get('/TReservationInfo/getUsersByRoomForEdit/1?date=2026-03-01');

        $this->assertResponseOk();
        $users = $this->json()['data']['usersByRoom'];
        $byId = array_column($users, null, 'id');
        $this->assertTrue($byId[1]['meals']['night']);
        $this->assertFalse($byId[1]['meals']['morning']);
        // 削除済みユーザー(6)は含まれない
        $this->assertArrayNotHasKey(6, $byId);
    }

    /** @return array<string, array{0:string}> */
    public static function badDates(): array
    {
        return [
            'missing' => [''],
            'invalid' => ['?date=2026-02-30'],
            'wrong format' => ['?date=20260301'],
        ];
    }

    /** @dataProvider badDates */
    public function testGetUsersByRoomForEdit_badDate_returns400(string $query): void
    {
        $this->login(1);
        $this->get('/TReservationInfo/getUsersByRoomForEdit/1' . $query);
        $this->assertResponseCode(400);
        $this->assertStringContainsString('YYYY-MM-DD', $this->json()['message']);
    }

    public function testGetUsersByRoomForEdit_child_forbidden(): void
    {
        $this->login(3);
        $this->get('/TReservationInfo/getUsersByRoomForEdit/1?date=2026-03-01');
        $this->assertResponseCode(403);
    }

    // ---- exportJson ----

    public function testExportJson_validRange_returnsOk(): void
    {
        $this->insertReservation(1, 1, '2026-03-02', 0, 1);
        $this->login(1);
        $this->get('/TReservationInfo/exportJson?from=2026-03-01&to=2026-03-03');
        $this->assertResponseOk();
        $this->assertTrue($this->json()['ok']);
    }

    /** @return array<string, array{0:string, 1:string}> */
    public static function badExportRanges(): array
    {
        return [
            'missing' => ['', '開始日・終了日を指定してください'],
            'garbage' => ['?from=not-a-date&to=2026-03-01', '日付の形式が正しくありません'],
            'reversed' => ['?from=2026-03-05&to=2026-03-01', '開始日は終了日以前'],
        ];
    }

    /** @dataProvider badExportRanges */
    public function testExportJson_badRange_returns400(string $query, string $message): void
    {
        $this->login(1);
        $this->get('/TReservationInfo/exportJson' . $query);
        $this->assertResponseCode(400);
        $this->assertStringContainsString($message, $this->json()['message']);
    }

    public function testExportJson_dbFailure_returns500(): void
    {
        $this->login(1);
        $this->getWithBrokenTable('/TReservationInfo/exportJson?from=2026-03-01&to=2026-03-03', 't_individual_reservation_info');
        $this->assertResponseCode(500);
        $this->assertStringNotContainsString('t_individual_reservation_info', (string)$this->_response->getBody());
    }

    public function testExportJson_child_forbidden(): void
    {
        $this->login(3);
        $this->get('/TReservationInfo/exportJson?from=2026-03-01&to=2026-03-03');
        $this->assertResponseCode(403);
    }

    // ---- exportJsonrank ----

    public function testExportJsonrank_validMonth_returnsOk(): void
    {
        $this->login(1);
        $this->get('/TReservationInfo/exportJsonrank?month=2026-03');
        $this->assertResponseOk();
    }

    public function testExportJsonrank_validPeriod_returnsOk(): void
    {
        $this->login(1);
        $this->get('/TReservationInfo/exportJsonrank?from=2026-03-01&to=2026-03-10');
        $this->assertResponseOk();
    }

    /** @return array<string, array{0:string, 1:string}> */
    public static function badRankQueries(): array
    {
        return [
            'no params' => ['', '無効な月'],
            'month format' => ['?month=2026-13', '無効な月'],
            'only from' => ['?from=2026-03-01', '無効な期間'],
            'bad date' => ['?from=2026/03/01&to=2026-03-10', '無効な期間'],
            'reversed' => ['?from=2026-03-10&to=2026-03-01', '無効な期間'],
        ];
    }

    /** @dataProvider badRankQueries */
    public function testExportJsonrank_badQuery_returns400(string $query, string $message): void
    {
        $this->login(1);
        $this->get('/TReservationInfo/exportJsonrank' . $query);
        $this->assertResponseCode(400);
        $this->assertStringContainsString($message, $this->json()['message']);
    }

    public function testExportJsonrank_dbFailure_returns500(): void
    {
        $this->login(1);
        $this->getWithBrokenTable('/TReservationInfo/exportJsonrank?month=2026-03', 't_individual_reservation_info');
        $this->assertResponseCode(500);
    }

    // ---- reportNoMeal / reportEat ----

    public function testReportNoMeal_cancelsTodaysReservation(): void
    {
        $today = date('Y-m-d');
        $this->insertReservation(3, 1, $today, 1, 1);
        $this->login(3);
        $this->post('/TReservationInfo/reportNoMeal');

        $this->assertResponseOk();
        $this->assertTrue($this->json()['ok']);
        $row = $this->getTableLocator()->get('TIndividualReservationInfo')->find()
            ->where(['i_id_user' => 3, 'i_reservation_type' => 1, 'd_reservation_date' => $today])->firstOrFail();
        $this->assertSame([0, 0], [(int)$row->eat_flag, (int)$row->i_change_flag]);
    }

    public function testReportNoMeal_userWithoutRoom_returns400(): void
    {
        ConnectionManager::get('test')->delete('m_user_group', ['i_id_user' => 3]);
        $this->login(3);
        $this->post('/TReservationInfo/reportNoMeal');
        $this->assertResponseCode(400);
        $this->assertStringContainsString('所属部屋', $this->json()['message']);
    }

    public function testReportNoMeal_get_notAllowed(): void
    {
        $this->login(3);
        $this->get('/TReservationInfo/reportNoMeal');
        $this->assertResponseError();
    }

    public function testReportEat_createsTodaysMeals(): void
    {
        $this->login(3);
        $this->post('/TReservationInfo/reportEat');

        $this->assertResponseOk();
        $this->assertTrue($this->json()['ok']);
        $count = $this->getTableLocator()->get('TIndividualReservationInfo')->find()
            ->where(['i_id_user' => 3, 'd_reservation_date' => date('Y-m-d'), 'eat_flag' => 1])->count();
        $this->assertGreaterThanOrEqual(1, $count);
    }

    public function testReportEat_userWithoutRoom_returns400(): void
    {
        ConnectionManager::get('test')->delete('m_user_group', ['i_id_user' => 3]);
        $this->login(3);
        $this->post('/TReservationInfo/reportEat');
        $this->assertResponseCode(400);
    }

    // ---- getAllRoomsMealCounts ----

    public function testGetAllRoomsMealCounts_admin_returnsResult(): void
    {
        $this->insertReservation(1, 1, '2026-03-02', 0, 1);
        $this->login(1);
        $this->get('/TReservationInfo/getAllRoomsMealCounts?from=2026-03-01&to=2026-03-31');
        $this->assertResponseOk();
        $this->assertArrayHasKey('result', $this->json()['data']);
    }

    public function testGetAllRoomsMealCounts_defaultRange_returnsOk(): void
    {
        $this->login(1);
        $this->get('/TReservationInfo/getAllRoomsMealCounts');
        $this->assertResponseOk();
    }

    public function testGetAllRoomsMealCounts_nonAdmin_forbidden(): void
    {
        $this->login(2);
        $this->get('/TReservationInfo/getAllRoomsMealCounts');
        $this->assertResponseCode(403);
    }

    public function testGetAllRoomsMealCounts_dbFailure_returns500(): void
    {
        $this->login(1);
        $this->getWithBrokenTable('/TReservationInfo/getAllRoomsMealCounts?from=2026-03-01&to=2026-03-31', 't_individual_reservation_info');
        $this->assertResponseCode(500);
    }

    // ---- getRoomMealCounts ----

    public function testGetRoomMealCounts_ownRoomFromPath_returnsResult(): void
    {
        $this->insertReservation(2, 1, '2026-03-02', 0, 1);
        $this->login(2);
        $this->get('/TReservationInfo/getRoomMealCounts/1?from=2026-03-01&to=2026-03-31');
        $this->assertResponseOk();
        $this->assertArrayHasKey('result', $this->json()['data']);
    }

    public function testGetRoomMealCounts_noRoomInPath_usesUsersRooms(): void
    {
        $this->login(2);
        $this->get('/TReservationInfo/getRoomMealCounts');
        $this->assertResponseOk();
    }

    public function testGetRoomMealCounts_userWithoutRoom_returns400(): void
    {
        ConnectionManager::get('test')->delete('m_user_group', ['i_id_user' => 2]);
        $this->login(2);
        $this->get('/TReservationInfo/getRoomMealCounts');
        $this->assertResponseCode(400);
        $this->assertStringContainsString('所属部屋', $this->json()['message']);
    }

    public function testGetRoomMealCounts_otherRoom_forbidden(): void
    {
        $this->login(2);
        $this->get('/TReservationInfo/getRoomMealCounts/99');
        $this->assertResponseCode(403);
    }

    public function testGetRoomMealCounts_dbFailure_returns500(): void
    {
        $this->login(2);
        $this->getWithBrokenTable('/TReservationInfo/getRoomMealCounts/1', 't_individual_reservation_info');
        $this->assertResponseCode(500);
    }
}
