<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * 管理者・システム管理者も、所属部屋以外は部屋の選択肢に出ず、直接指定しても扱えない（#821）。
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
        $this->assertNull($this->viewVariable('calRoomId'), '所属外の部屋は指定しても選ばれない');
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

    /** @dataProvider adminProvider */
    public function testMealCountGridRoomsAndUsersAreAffiliatedOnly(int $userId, array $expectedRoomIds): void
    {
        $this->loginAs($userId);
        $this->get('/TReservationInfo/meal-count-grid?mode=individual&user_id=5');

        $this->assertResponseOk();
        $this->assertSame($expectedRoomIds, array_keys($this->viewVariable('allRooms')));
        $this->assertArrayNotHasKey(5, $this->viewVariable('nameList'), '所属外の部屋の利用者は選択肢に出ない');
        $this->assertSame($userId, $this->viewVariable('selectedUserId'), '所属外の利用者を指定しても本人に戻る');
    }

    public function testMealCountGridRoomModeFallsBackToOwnRoom(): void
    {
        $this->loginAs(1);
        $this->get('/TReservationInfo/meal-count-grid?mode=room&room_id=71');

        $this->assertResponseOk();
        $this->assertSame(1, $this->viewVariable('selectedRoomId'));
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

    /** 事務所ユーザーを兼ねる管理者は、事務所ユーザーと同じ扱い（食数一括管理の部屋・全部屋モードは所属部屋のみ） */
    public function testOfficeAdminMealCountGridRoomAndAllModesAreAffiliatedOnly(): void
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
        $this->assertSame([1, 70, 74], array_keys($this->viewVariable('allRooms')));

        $this->get('/TReservationInfo/meal-count-grid?mode=room&room_id=71');
        $this->assertNotSame(71, $this->viewVariable('selectedRoomId'));
    }
}
