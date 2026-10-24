<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * 管理者・システム管理者も、所属部屋以外は部屋の選択肢に出ず、直接指定しても扱えない（#821）。
 * ただしエクセル食数予約（画面と bulk-toggle）だけは、管理者・システム管理者が全部屋を扱える（#878）。
 *
 * 部屋 1: 管理者(user 1)が所属（fixture）
 * 部屋 70: 管理者(user 1)・システム管理者(user 4)が所属
 * 部屋 71: どちらも未所属（user 5 だけが所属）
 */
class AdminRoomScopeTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TIndividualReservationInfo',
    ];

    private const ACCOUNTS = [1 => 'admin_user', 4 => 'system_admin_user'];

    private mixed $original = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = Configure::read('Features.actualMeal');
        Configure::write('Features.actualMeal', true);

        $db = ConnectionManager::get('test');
        foreach ([70, 71] as $id) {
            $db->insert('m_room_info', ['i_id_room' => $id, 'c_room_name' => '部屋' . $id, 'i_disp_no' => $id, 'i_enable' => 1, 'i_del_flg' => 0]);
        }
        foreach ([[1, 70], [4, 70], [5, 71]] as [$userId, $roomId]) {
            $db->insert('m_user_group', [
                'i_id_user' => $userId, 'i_id_room' => $roomId, 'active_flag' => 0,
                'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't', 'dt_update' => '2026-01-01 00:00:00', 'c_update_user' => 't',
            ]);
        }
    }

    protected function tearDown(): void
    {
        Configure::write('Features.actualMeal', $this->original);
        parent::tearDown();
    }

    private function loginAs(int $userId): void
    {
        // identity は c_login_account で DB から引き直されるため、権限は DB の i_admin で決まる
        $this->session(['Auth' => ['i_id_user' => $userId, 'c_login_account' => self::ACCOUNTS[$userId], 'c_user_name' => 'a', 'i_admin' => 0, 'i_user_level' => 0]]);
    }

    /** @return array<int, int> */
    public static function adminProvider(): array
    {
        return ['管理者' => [1, [1, 70]], 'システム管理者' => [4, [70]]];
    }

    /** @return array<string, array{0: int}> */
    public static function adminIdProvider(): array
    {
        return ['管理者' => [1], 'システム管理者' => [4]];
    }

    /**
     * @dataProvider adminProvider
     * @param array<int> $expectedRoomIds
     */
    public function testIndexRoomsAreAffiliatedOnly(int $userId, array $expectedRoomIds): void
    {
        $this->loginAs($userId);
        $this->get('/TReservationInfo?cal_room_id=71');

        $this->assertResponseOk();
        $this->assertSame($expectedRoomIds, array_keys($this->viewVariable('rooms')));
        $this->assertSame($expectedRoomIds, array_keys($this->viewVariable('authorizedRooms')));
        $this->assertFalse($this->viewVariable('canViewAllRooms'), '全部屋の集計は事務所ユーザーのみ');
        $this->assertSame($expectedRoomIds[0], $this->viewVariable('calRoomId'), '所属外の部屋は指定しても選ばれず、既定の部屋になる');
    }

    /** @dataProvider adminProvider */
    public function testViewIgnoresNotMemberRoom(int $userId, array $expectedRoomIds): void
    {
        $this->loginAs($userId);
        $this->get('/TReservationInfo/view/' . date('Y-m-d') . '?room_id=71');

        $this->assertResponseOk();
        $this->assertSame($expectedRoomIds, array_keys($this->viewVariable('authorizedRooms')));
        $this->assertSame($expectedRoomIds[0], $this->viewVariable('activeRoomId'));
    }

    /** @dataProvider adminProvider */
    public function testActualMealManagementRoomsAreAffiliatedOnly(int $userId, array $expectedRoomIds): void
    {
        $this->loginAs($userId);
        $this->get('/TReservationInfo/actual-meal-management?room_id=71');

        $this->assertResponseOk();
        $this->assertSame($expectedRoomIds, array_keys($this->viewVariable('rooms')));
        $this->assertSame($expectedRoomIds[0], $this->viewVariable('selectedRoomId'));
    }

    /**
     * エクセル食数予約だけは、管理者・システム管理者に全部屋（削除済みを除く）と全利用者を出す
     *
     * @dataProvider adminIdProvider
     */
    public function testMealCountGridShowsAllRoomsAndUsersToAdmins(int $userId): void
    {
        $this->loginAs($userId);
        $this->get('/TReservationInfo/meal-count-grid?mode=individual&user_id=5');

        $this->assertResponseOk();
        $this->assertSame([1, 70, 71], array_keys($this->viewVariable('allRooms')));
        $this->assertArrayHasKey(5, $this->viewVariable('nameList'), '所属外の部屋の利用者も選べる');
        $this->assertSame(5, $this->viewVariable('selectedUserId'));

        $this->get('/TReservationInfo/meal-count-grid?mode=room&room_id=71');
        $this->assertResponseOk();
        $this->assertSame(71, $this->viewVariable('selectedRoomId'), '所属外の部屋も選べる');

        $this->get('/TReservationInfo/meal-count-grid?mode=all');
        $this->assertResponseOk();
        $this->assertResponseContains('<tr data-user-id="5" data-room-id="71"', '全部モードに所属外の部屋の行が出る');
    }

    /** 部屋71に児童(user 3)を所属させる */
    private function childInRoom71(): void
    {
        ConnectionManager::get('test')->insert('m_user_group', [
            'i_id_user' => 3, 'i_id_room' => 71, 'active_flag' => 0,
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't', 'dt_update' => '2026-01-01 00:00:00', 'c_update_user' => 't',
        ]);
    }

    private function bulkToggleRoom71(int $roomId = 71): array
    {
        $this->enableCsrfToken();
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->post('/TReservationInfo/bulk-toggle', ['items' => [[
            'roomId' => $roomId, 'userId' => 3, 'date' => date('Y-m-d', strtotime('+40 days')), 'meal' => 3, 'value' => 1,
        ]]]);
        $this->assertResponseOk();

        $body = json_decode((string)$this->_response->getBody(), true);

        return $body['data'] ?? $body;
    }

    private function reservationCount(int $roomId): int
    {
        return (int)ConnectionManager::get('test')->execute(
            'SELECT COUNT(*) FROM t_individual_reservation_info WHERE i_id_user = 3 AND i_id_room = ?',
            [$roomId]
        )->fetchColumn(0);
    }

    /**
     * 管理者でも bulk-toggle で、対象者が所属していない部屋・削除済みの部屋には登録できない
     *
     * @dataProvider adminIdProvider
     */
    public function testMealCountGridBulkToggleStillRejectsNotAffiliatedTargetAndDeletedRoom(int $userId): void
    {
        $this->loginAs($userId);

        $data = $this->bulkToggleRoom71();
        $this->assertSame(0, $data['ok'] ?? null);
        $this->assertSame('対象ユーザーは指定された部屋に所属していません。', $data['results'][0]['message'] ?? null);

        $db = ConnectionManager::get('test');
        $db->insert('m_room_info', ['i_id_room' => 75, 'c_room_name' => '部屋75', 'i_disp_no' => 75, 'i_enable' => 1, 'i_del_flg' => 1]);
        $db->insert('m_user_group', [
            'i_id_user' => 3, 'i_id_room' => 75, 'active_flag' => 0,
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't', 'dt_update' => '2026-01-01 00:00:00', 'c_update_user' => 't',
        ]);
        $data = $this->bulkToggleRoom71(75);
        $this->assertSame(0, $data['ok'] ?? null);
        $this->assertSame('選択された部屋は利用できません。', $data['results'][0]['message'] ?? null);
        $this->assertSame(0, $this->reservationCount(71) + $this->reservationCount(75), '予約行は作られない');
    }

    /**
     * 管理者は bulk-toggle で、所属外の部屋に残った退出済みの人の予約を取り消せる。予約が無ければ取消も拒否する
     *
     * @dataProvider adminIdProvider
     */
    public function testMealCountGridBulkToggleCancelsLeftoverOfExitedTargetInNotMemberRoom(int $userId): void
    {
        $db = ConnectionManager::get('test');
        $date = date('Y-m-d', strtotime('+40 days'));
        $db->insert('m_user_group', [
            'i_id_user' => 3, 'i_id_room' => 71, 'active_flag' => 1, // 退出済み
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't', 'dt_update' => '2026-01-01 00:00:00', 'c_update_user' => 't',
        ]);
        $db->insert('t_individual_reservation_info', [
            'd_reservation_date' => $date, 'i_id_user' => 3, 'i_reservation_type' => 3, 'i_id_room' => 71,
            'eat_flag' => 1, 'i_change_flag' => 1, 'i_version' => 1, 'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't',
        ]);
        $this->loginAs($userId);
        $this->enableCsrfToken();
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->post('/TReservationInfo/bulk-toggle', ['items' => [
            ['roomId' => 71, 'userId' => 3, 'date' => $date, 'meal' => 3, 'value' => 0], // 既存の予約あり
            ['roomId' => 71, 'userId' => 3, 'date' => $date, 'meal' => 1, 'value' => 0], // 既存の予約なし
        ]]);
        $this->assertResponseOk();
        $body = json_decode((string)$this->_response->getBody(), true);
        $results = ($body['data'] ?? $body)['results'];

        $this->assertTrue($results[0]['ok'], json_encode($results, JSON_UNESCAPED_UNICODE));
        $this->assertFalse($results[1]['ok']);
        $this->assertSame('対象ユーザーは指定された部屋に所属していません。', $results[1]['message'] ?? null);
        $this->assertSame(1, $this->reservationCount(71), '取消は行を消さずに無効化し、予約の無い食事に行は作らない');
        $eat = $db->execute(
            'SELECT eat_flag FROM t_individual_reservation_info WHERE i_id_user = 3 AND i_id_room = 71 AND i_reservation_type = 3'
        )->fetchColumn(0);
        $this->assertSame(0, (int)$eat, '既存の予約は取り消される');
    }

    /** ブロック長は bulk-toggle でも所属外の部屋の他人の予約を登録できない（管理者だけの例外） */
    public function testMealCountGridBulkToggleRejectsBlockLeaderInNotMemberRoom(): void
    {
        ConnectionManager::get('test')->update('m_user_info', ['i_admin' => 2], ['i_id_user' => 1]);
        $this->childInRoom71();
        $this->loginAs(1);

        $data = $this->bulkToggleRoom71();
        $this->assertSame(0, $data['ok'] ?? null);
        $this->assertSame('この部屋を操作する権限がありません。', $data['results'][0]['message'] ?? null);
        $this->assertSame(0, $this->reservationCount(71));
    }

    /**
     * エクセル食数予約の保存（bulk-toggle）では、管理者・システム管理者は所属外の部屋も登録できる
     *
     * @dataProvider adminIdProvider
     */
    public function testMealCountGridBulkToggleAllowsAdminsInNotMemberRoom(int $userId): void
    {
        $this->childInRoom71();
        $this->loginAs($userId);

        $data = $this->bulkToggleRoom71();
        $this->assertSame(1, $data['ok'] ?? null, json_encode($data, JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, $this->reservationCount(71), '所属外の部屋に予約行ができる');
    }

    /**
     * ほかの予約画面（単件 toggle）は、管理者・システム管理者でも所属外の部屋を操作できないまま（#821）
     *
     * @dataProvider adminIdProvider
     */
    public function testSingleToggleStillRejectsAdminInNotMemberRoom(int $userId): void
    {
        $this->childInRoom71();
        $this->loginAs($userId);
        $this->enableCsrfToken();
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->post('/TReservationInfo/toggle/71', ['date' => date('Y-m-d', strtotime('+40 days')), 'meal' => 3, 'value' => 1, 'userId' => 3]);

        $this->assertResponseCode(403);
    }

    /** 事務所ユーザー（管理者でない）は、エクセル食数予約でも所属外の部屋の他人の予約を登録できない */
    public function testMealCountGridBulkToggleRejectsOfficeUserInNotMemberRoom(): void
    {
        $db = ConnectionManager::get('test');
        $db->insert('m_room_info', ['i_id_room' => 74, 'c_room_name' => '事務所', 'i_disp_no' => 74, 'i_enable' => 1, 'i_del_flg' => 0]);
        $db->insert('m_user_group', [
            'i_id_user' => 1, 'i_id_room' => 74, 'active_flag' => 0,
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't', 'dt_update' => '2026-01-01 00:00:00', 'c_update_user' => 't',
        ]);
        $db->update('m_user_info', ['i_admin' => 0], ['i_id_user' => 1]);
        $this->childInRoom71();
        $this->loginAs(1);

        $data = $this->bulkToggleRoom71();
        $this->assertSame(0, $data['ok'] ?? null, json_encode($data, JSON_UNESCAPED_UNICODE));
        $this->assertSame('この部屋を操作する権限がありません。', $data['results'][0]['message'] ?? null, '所属外の部屋として拒否される');
        $this->assertSame(0, $this->reservationCount(71));
    }

    public function testCopyPreviewRejectsNotMemberRoom(): void
    {
        $this->loginAs(1);
        $query = 'mode=week&source_start=2026-06-01&target_start=2026-06-08';

        $this->get('/TReservationInfo/copyPreview?' . $query . '&room_id=71');
        $this->assertResponseCode(403);

        $this->get('/TReservationInfo/copyPreview?' . $query . '&room_id=70');
        $this->assertResponseOk();
    }

    public function testCopyRejectsNotMemberRoom(): void
    {
        $this->loginAs(1);
        $this->enableCsrfToken();
        $this->post('/TReservationInfo/copy', ['mode' => 'week', 'source_start' => '2026-06-01', 'target_start' => '2026-06-08', 'room_id' => 71]);

        $this->assertResponseCode(403);
    }

    public function testCopyAllRoomsCopiesAffiliatedRoomsOnly(): void
    {
        $db = ConnectionManager::get('test');
        $nextMonday = date('Y-m-d', strtotime('monday next week'));
        $srcMonday  = date('Y-m-d', strtotime($nextMonday . ' +7 days'));
        $dstMonday  = date('Y-m-d', strtotime($nextMonday . ' +14 days'));
        foreach ([[2, 1], [5, 71]] as [$userId, $roomId]) {
            $db->insert('t_individual_reservation_info', [
                'd_reservation_date' => $srcMonday, 'i_id_user' => $userId, 'i_reservation_type' => 1, 'i_id_room' => $roomId,
                'eat_flag' => 1, 'i_change_flag' => 1, 'i_version' => 1, 'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't',
            ]);
        }

        $this->loginAs(1);
        $this->enableCsrfToken();
        $this->post('/TReservationInfo/copy', ['mode' => 'week', 'source_start' => $srcMonday, 'target_start' => $dstMonday]);

        $this->assertResponseOk();
        $count = fn(int $roomId): int => (int)$db->execute(
            'SELECT COUNT(*) FROM t_individual_reservation_info WHERE d_reservation_date = ? AND i_id_room = ?',
            [$dstMonday, $roomId]
        )->fetchColumn(0);
        $this->assertSame(1, $count(1), '所属部屋はコピーされる');
        $this->assertSame(0, $count(71), '所属外の部屋はコピーされない');
    }

    public function testAdminWithoutAffiliationHasNoRooms(): void
    {
        ConnectionManager::get('test')->delete('m_user_group', ['i_id_user' => 4]);
        $this->loginAs(4);

        $this->get('/TReservationInfo');
        $this->assertResponseOk();
        $this->assertSame([], $this->viewVariable('rooms'), '全部屋へフォールバックしない');

        $this->enableCsrfToken();
        $this->post('/TReservationInfo/copy', ['mode' => 'week', 'source_start' => '2026-06-01', 'target_start' => '2026-06-08']);
        $this->assertResponseCode(403);
        $this->assertResponseContains('所属している部屋がないため');
    }

    /** 予約カレンダーの食数: 削除済みの部屋にしか所属がない・所属が無いときは、全部屋や削除済みの部屋の集計にならない */
    public function testCalendarCountsExcludeNotMemberAndDeletedRooms(): void
    {
        $db = ConnectionManager::get('test');
        $db->insert('m_room_info', ['i_id_room' => 73, 'c_room_name' => '部屋73', 'i_disp_no' => 73, 'i_enable' => 1, 'i_del_flg' => 1]);
        // システム管理者(user 4)の所属は、削除済みの部屋73だけにする
        $db->delete('m_user_group', ['i_id_user' => 4]);
        $db->insert('m_user_group', [
            'i_id_user' => 4, 'i_id_room' => 73, 'active_flag' => 0,
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't', 'dt_update' => '2026-01-01 00:00:00', 'c_update_user' => 't',
        ]);
        foreach ([[5, 71], [4, 73]] as [$userId, $roomId]) {
            $db->insert('t_individual_reservation_info', [
                'd_reservation_date' => date('Y-m-d'), 'i_id_user' => $userId, 'i_reservation_type' => 2, 'i_id_room' => $roomId,
                'eat_flag' => 1, 'i_change_flag' => 1, 'i_version' => 1, 'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't',
            ]);
        }

        $this->loginAs(4);
        $this->get('/TReservationInfo');
        $this->assertResponseOk();
        $this->assertSame([], $this->viewVariable('mealDataArray'), '所属外・削除済みの部屋の食数は数えない');

        $db->delete('m_user_group', ['i_id_user' => 4]);
        $this->get('/TReservationInfo');
        $this->assertSame([], $this->viewVariable('mealDataArray'), '所属部屋が無くても全部屋の集計にならない');
    }

    /** 事務所ユーザーを兼ねる管理者も、エクセル食数予約の部屋・全部屋モードは全部屋 */
    public function testOfficeAdminMealCountGridRoomAndAllModesShowAllRooms(): void
    {
        $db = ConnectionManager::get('test');
        $db->insert('m_room_info', ['i_id_room' => 74, 'c_room_name' => '事務所', 'i_disp_no' => 74, 'i_enable' => 1, 'i_del_flg' => 0]);
        $db->insert('m_user_group', [
            'i_id_user' => 1, 'i_id_room' => 74, 'active_flag' => 0,
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't', 'dt_update' => '2026-01-01 00:00:00', 'c_update_user' => 't',
        ]);
        $this->loginAs(1);

        $this->get('/TReservationInfo/meal-count-grid?mode=all');
        $this->assertResponseOk();
        $this->assertSame([1, 70, 71, 74], array_keys($this->viewVariable('allRooms')));

        $this->get('/TReservationInfo/meal-count-grid?mode=room&room_id=71');
        $this->assertSame(71, $this->viewVariable('selectedRoomId'));
    }

    /** @return array<string, array{0: int}> */
    public static function officeRoleProvider(): array
    {
        return ['事務所兼管理者' => [1], '事務所ユーザー（管理者でない）' => [0]];
    }

    /**
     * エクセル食数予約（個人）: 所属外の部屋の児童の行は、管理者なら編集でき、事務所ユーザーは閲覧のみ
     *
     * @dataProvider officeRoleProvider
     */
    public function testOfficeIndividualGridRowsOfNotMemberRoom(int $iAdmin): void
    {
        ConnectionManager::get('test')->update('m_user_info', ['i_admin' => $iAdmin], ['i_id_user' => 1]);
        $db = ConnectionManager::get('test');
        $db->insert('m_room_info', ['i_id_room' => 74, 'c_room_name' => '事務所', 'i_disp_no' => 74, 'i_enable' => 1, 'i_del_flg' => 0]);
        foreach ([[1, 74], [3, 71]] as [$userId, $roomId]) { // 管理者は事務所にも所属、児童(user 3)は部屋1と所属外の71に所属
            $db->insert('m_user_group', [
                'i_id_user' => $userId, 'i_id_room' => $roomId, 'active_flag' => 0,
                'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't', 'dt_update' => '2026-01-01 00:00:00', 'c_update_user' => 't',
            ]);
        }
        $this->loginAs(1);
        $this->get('/TReservationInfo/meal-count-grid?mode=individual&user_id=3&week=' . date('Y-m-d', strtotime('monday next week')));
        $this->assertResponseOk();

        $body = (string)$this->_response->getBody();
        $row = function (int $roomId) use ($body): string {
            $start = strpos($body, '<tr data-user-id="3" data-room-id="' . $roomId . '"');
            $this->assertNotFalse($start, "部屋{$roomId}の行が表示されている");

            return substr($body, $start, strpos($body, '</tr>', $start) - $start);
        };
        $this->assertStringContainsString('mcg-toggleable', $row(1), '所属部屋の児童は編集できる');
        if ($iAdmin === 1) {
            $this->assertStringContainsString('mcg-toggleable', $row(71), '管理者は所属外の部屋の児童も編集できる');

            return;
        }
        $this->assertStringNotContainsString('mcg-toggleable', $row(71), '所属外の部屋の児童は編集できない');
        $this->assertStringContainsString('所属していない部屋の予約は操作できません。', $row(71), '閲覧のみの理由を出す');
    }

    /** 予約コピーの API は ReservationCopy のルートを指す（以前は存在しない TReservationInfo::copy を指していた） */
    public function testCopyApiUrlsPointToReservationCopyRoutes(): void
    {
        $this->loginAs(1);
        $this->get('/TReservationInfo');

        $this->assertResponseContains('copyApi: "/TReservationInfo/copy"');
        $this->assertResponseContains('copyPreviewApi: "/TReservationInfo/copyPreview"');
    }

    /** events API の合計食数は所属部屋のみ（管理者も同じ） */
    public function testEventsTotalsAreAffiliatedRoomsOnly(): void
    {
        $db = ConnectionManager::get('test');
        $date = date('Y-m-d', strtotime('+3 days'));
        foreach ([[4, 70], [5, 71]] as [$userId, $roomId]) {
            $db->insert('t_individual_reservation_info', [
                'd_reservation_date' => $date, 'i_id_user' => $userId, 'i_reservation_type' => 2, 'i_id_room' => $roomId,
                'eat_flag' => 1, 'i_change_flag' => 1, 'i_version' => 1, 'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't',
            ]);
        }
        $this->loginAs(4);
        $this->get('/TReservationInfo/events?start=' . $date . '&end=' . date('Y-m-d', strtotime($date . ' +1 day')));

        $this->assertResponseOk();
        $this->assertResponseNotContains('合計食数: 2');
        $this->assertResponseContains('合計食数: 1');
    }

    /** 一括登録フォームの送信先（bulk-add-submit）が一括登録の処理に届く */
    public function testBulkAddSubmitDashedRouteReachesBulkController(): void
    {
        $this->loginAs(1);
        $this->enableCsrfToken();
        $this->configRequest(['headers' => ['Accept' => 'application/json', 'X-Requested-With' => 'XMLHttpRequest']]);
        $this->post('/TReservationInfo/bulk-add-submit', [
            'reservation_type' => 'group',
            'i_id_room' => 71,
            'day_users' => [date('Y-m-d', strtotime('+30 days')) => ['5' => ['1' => '1']]],
        ]);

        $this->assertResponseContains('所属していない部屋には登録できません。');
    }

    /** 事務所ユーザーの全部屋の合計からも、削除済みの部屋の予約を除く */
    public function testOfficeUserTotalsExcludeDeletedRooms(): void
    {
        $db = ConnectionManager::get('test');
        $db->insert('m_room_info', ['i_id_room' => 74, 'c_room_name' => '事務所', 'i_disp_no' => 74, 'i_enable' => 1, 'i_del_flg' => 0]);
        $db->insert('m_room_info', ['i_id_room' => 73, 'c_room_name' => '部屋73', 'i_disp_no' => 73, 'i_enable' => 1, 'i_del_flg' => 1]);
        $db->insert('m_user_group', [
            'i_id_user' => 4, 'i_id_room' => 74, 'active_flag' => 0,
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't', 'dt_update' => '2026-01-01 00:00:00', 'c_update_user' => 't',
        ]);
        $date = date('Y-m-d', strtotime('+3 days'));
        foreach ([[5, 71], [5, 73]] as [$userId, $roomId]) { // 71 は有効（所属外でも事務所ユーザーは数える）、73 は削除済み
            $db->insert('t_individual_reservation_info', [
                'd_reservation_date' => $date, 'i_id_user' => $userId, 'i_reservation_type' => $roomId === 71 ? 2 : 3, 'i_id_room' => $roomId,
                'eat_flag' => 1, 'i_change_flag' => 1, 'i_version' => 1, 'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't',
            ]);
        }
        $this->loginAs(4);
        $this->get('/TReservationInfo/events?start=' . $date . '&end=' . date('Y-m-d', strtotime($date . ' +1 day')));

        $this->assertResponseOk();
        $this->assertResponseContains('合計食数: 1');
    }
}
