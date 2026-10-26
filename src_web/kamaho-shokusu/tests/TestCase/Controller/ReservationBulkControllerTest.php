<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Datasource\ConnectionManager;
use Cake\I18n\Date;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * ReservationBulkController（一括登録・直前一括編集・スナップショット・部屋別利用者）のテスト。
 */
class ReservationBulkControllerTest extends TestCase
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

    private function loginAdmin(): void
    {
        $this->login(1, 'admin_user', 1, 0);
    }

    private function loginStaff(): void
    {
        $this->login(2, 'staff_user', 0, 0);
    }

    private function loginChild(): void
    {
        $this->login(3, 'non_staff_user', 0, 1);
    }

    private function login(int $id, string $account, int $admin, int $level): void
    {
        $this->session(['Auth' => [
            'i_id_user' => $id, 'c_login_account' => $account, 'c_user_name' => 'テスト' . $id,
            'i_admin' => $admin, 'i_user_level' => $level, 'i_id_room' => 1,
        ]]);
        $this->enableCsrfToken();
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
    }

    private function farDate(int $days = 30): string
    {
        return Date::today()->addDays($days)->format('Y-m-d');
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return (array)json_decode((string)$this->_response->getBody(), true);
    }

    private function seed(int $userId, string $date, int $type, int $eat, int $change, ?string $updated = null): void
    {
        ConnectionManager::get('test')->insert('t_individual_reservation_info', [
            'i_id_user' => $userId, 'd_reservation_date' => $date, 'i_reservation_type' => $type,
            'i_id_room' => 1, 'eat_flag' => $eat, 'i_change_flag' => $change, 'i_version' => 1,
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't',
            'dt_update' => $updated, 'c_update_user' => $updated === null ? null : 't',
        ]);
    }

    private function row(int $userId, string $date, int $type): ?\Cake\Datasource\EntityInterface
    {
        return $this->getTableLocator()->get('TIndividualReservationInfo')->find()
            ->where(['i_id_user' => $userId, 'i_id_room' => 1, 'd_reservation_date' => $date, 'i_reservation_type' => $type])
            ->first();
    }

    // ---- getUsersByRoomForBulk ----

    public function testGetUsersByRoomForBulk_returnsUsersOfRoom(): void
    {
        $this->loginAdmin();
        $this->get('/TReservationInfo/getUsersByRoomForBulk/1?date=' . $this->farDate());

        $this->assertResponseOk();
        $this->assertTrue($this->json()['ok']);
        $this->assertArrayHasKey('users', $this->json()['data']);
    }

    public function testGetUsersByRoomForBulk_outOfRangePagingIsClamped(): void
    {
        $this->loginAdmin();
        $this->get('/TReservationInfo/getUsersByRoomForBulk/1?date=' . $this->farDate() . '&page=-3&limit=99999');

        $this->assertResponseOk();
    }

    public function testGetUsersByRoomForBulk_otherRoomIsForbidden(): void
    {
        ConnectionManager::get('test')->insert('m_room_info', [
            'i_id_room' => 2, 'c_room_name' => '部屋2', 'i_disp_no' => 2, 'i_enable' => 1, 'i_del_flg' => 0,
            'dt_create' => '2024-07-29 09:11:06', 'c_create_user' => 't',
        ]);
        $this->loginStaff();
        $this->get('/TReservationInfo/getUsersByRoomForBulk/2?date=' . $this->farDate());

        $this->assertResponseCode(403);
    }

    // ---- getReservationSnapshots ----

    public function testGetReservationSnapshots_returnsLatestUpdatePerDate(): void
    {
        $date = $this->farDate();
        $this->seed(3, $date, 1, 1, 1, '2026-03-04 05:06:07');
        $this->loginAdmin();
        $this->post('/TReservationInfo/getReservationSnapshots', ['room_id' => 1, 'dates' => [$date, '', 123]]);

        $this->assertResponseOk();
        $snapshots = $this->json()['data']['snapshots'];
        $this->assertSame('2026-03-04 05:06:07', $snapshots[$date]);
    }

    public function testGetReservationSnapshots_missingParamsReturn400(): void
    {
        $this->loginAdmin();
        $this->post('/TReservationInfo/getReservationSnapshots', ['room_id' => 1]);
        $this->assertResponseCode(400);

        $this->post('/TReservationInfo/getReservationSnapshots', ['room_id' => 1, 'dates' => 'not-array']);
        $this->assertResponseCode(400);
        $this->assertResponseContains('room_id または dates が不足しています');
    }

    public function testGetReservationSnapshots_otherRoomIsForbidden(): void
    {
        $this->loginAdmin();
        $this->post('/TReservationInfo/getReservationSnapshots', ['room_id' => 99, 'dates' => [$this->farDate()]]);

        $this->assertResponseCode(403);
    }

    // ---- bulkAddForm / bulkChangeEditForm ----

    public function testBulkAddForm_missingDateRedirectsToIndexWithFlash(): void
    {
        $this->loginAdmin();
        $this->get('/TReservationInfo/bulk-add-form');

        $this->assertRedirect(['controller' => 'TReservationInfo', 'action' => 'index']);
        $this->assertFlashMessage('日付が指定されていません。');
    }

    public function testBulkAddForm_invalidDateRedirectsToIndexWithFlash(): void
    {
        $this->loginAdmin();
        $this->get('/TReservationInfo/bulk-add-form?date=' . urlencode('not a date at all'));

        $this->assertRedirect(['controller' => 'TReservationInfo', 'action' => 'index']);
        $this->assertFlashMessage('無効な日付が指定されました。');
    }

    public function testBulkAddForm_rendersWithRoomsAndRoleFlags(): void
    {
        $this->loginAdmin();
        $this->get('/TReservationInfo/bulk-add-form?date=' . $this->farDate() . '&room_id=1');

        $this->assertResponseOk();
        $this->assertSame('1', $this->viewVariable('selectedRoomId'));
        $this->assertArrayHasKey(1, $this->viewVariable('rooms'));
        $this->assertSame([1], $this->viewVariable('loginRoomIds'));
        $this->assertTrue($this->viewVariable('isAdmin'));
        $this->assertTrue($this->viewVariable('canGroup'));
    }

    public function testBulkAddForm_staffCanUseGroupMode(): void
    {
        $this->loginStaff();
        $this->get('/TReservationInfo/bulk-add-form?date=' . $this->farDate());

        $this->assertResponseOk();
        $this->assertTrue($this->viewVariable('canGroup'));
        $this->assertFalse($this->viewVariable('isAdmin'));
    }

    public function testBulkAddForm_childIsDenied(): void
    {
        $this->loginChild();
        $this->get('/TReservationInfo/bulk-add-form?date=' . $this->farDate());

        $this->assertResponseCode(403);
    }

    public function testBulkChangeEditForm_missingDateRedirectsToIndexWithFlash(): void
    {
        $this->loginAdmin();
        $this->get('/TReservationInfo/bulk-change-edit-form');

        $this->assertRedirect(['controller' => 'TReservationInfo', 'action' => 'index']);
        $this->assertFlashMessage('日付が指定されていません。');
    }

    public function testBulkChangeEditForm_rendersForAdmin(): void
    {
        $this->loginAdmin();
        $this->get('/TReservationInfo/bulk-change-edit-form?date=' . $this->farDate(3) . '&room_id=1');

        $this->assertResponseOk();
        $this->assertArrayHasKey(1, $this->viewVariable('rooms'));
        $this->assertTrue($this->viewVariable('isAdmin'));
    }

    // ---- bulkChangeEditSubmit ----

    public function testBulkChangeEditSubmit_createsAndCancelsReservations(): void
    {
        $date = $this->farDate();
        $this->seed(3, $date, 3, 1, 1);
        $this->loginAdmin();
        $this->post('/TReservationInfo/bulk-change-edit-submit', [
            'i_id_room' => 1,
            'day_users' => [$date => [3 => [1 => 1, 3 => 0], 2 => [1 => 1]]],
        ]);

        $this->assertResponseOk();
        $data = $this->json()['data'];
        $this->assertSame(1, $data['updated']);
        $this->assertSame(2, $data['created']);
        $this->assertSame([], $data['skipped']);
        $this->assertSame('./', $data['redirect']);
        $this->assertSame(0, (int)$this->row(3, $date, 3)->i_change_flag);
        $this->assertNotNull($this->row(3, $date, 1));
        $this->assertNotNull($this->row(2, $date, 1));
    }

    public function testBulkChangeEditSubmit_missingRoomOrContentReturnsError(): void
    {
        $this->loginAdmin();
        $this->post('/TReservationInfo/bulk-change-edit-submit', ['i_id_room' => 1]);
        $this->assertResponseCode(400);
        $this->assertResponseContains('部屋または予約内容が指定されていません。');

        // 部屋の指定が無いときは、権限判定の段階で拒否される（保存には進まない）
        $this->post('/TReservationInfo/bulk-change-edit-submit', ['day_users' => [$this->farDate() => [3 => [1 => 1]]]]);
        $this->assertResponseCode(403);
        $this->assertNull($this->row(3, $this->farDate(), 1));
    }

    public function testBulkChangeEditSubmit_staleSnapshotReturns409WithConflictDate(): void
    {
        $date = $this->farDate();
        $this->seed(3, $date, 3, 1, 1, '2026-06-01 10:00:00');
        $this->loginAdmin();
        $this->post('/TReservationInfo/bulk-change-edit-submit', [
            'i_id_room' => 1,
            'day_users' => [$date => [3 => [3 => 0]]],
            'reservation_snapshot' => [$date => '2026-05-01 00:00:00'],
        ]);

        $this->assertResponseCode(409);
        $body = $this->json();
        $this->assertFalse($body['ok']);
        $this->assertSame($date, $body['data']['conflict_date']);
        $this->assertSame(1, (int)$this->row(3, $date, 3)->i_change_flag);
    }

    public function testBulkChangeEditSubmit_editingOtherUsersWithoutPermissionReturns422(): void
    {
        $date = $this->farDate();
        // 児童(user 3)が、他の利用者の予約を作ろうとする → 入口は通るが、サービスが権限なしで拒否する
        ConnectionManager::get('test')->insert('m_user_info', [
            'i_id_user' => 20, 'c_login_account' => 'other', 'c_login_passwd' => 'x', 'c_user_name' => '他部屋',
            'i_user_level' => 1, 'i_admin' => 0, 'i_del_flag' => 0, 'dt_create' => '2026-01-01 00:00:00',
        ]);
        $this->loginChild();
        $this->post('/TReservationInfo/bulk-change-edit-submit', [
            'i_id_room' => 1,
            'day_users' => [$date => [20 => [1 => 1]]],
        ]);

        $this->assertResponseCode(422);
        $this->assertFalse($this->json()['ok']);
        $this->assertSame('他ユーザーの予約を更新する権限がありません。', $this->json()['message']);
        $this->assertNull($this->row(20, $date, 1));
    }

    public function testBulkChangeEditSubmit_staffCannotCancelStaffLastMinuteAndGetsReason(): void
    {
        $date = Date::today()->addDays(3)->format('Y-m-d');
        $this->seed(2, $date, 3, 0, 1);
        $this->loginStaff();
        $this->post('/TReservationInfo/bulk-change-edit-submit', [
            'i_id_room' => 1,
            'day_users' => [$date => [2 => [3 => 0]]],
        ]);

        $this->assertResponseOk();
        $body = $this->json();
        $this->assertSame(0, $body['data']['updated']);
        $this->assertCount(1, $body['data']['skipped']);
        $this->assertStringContainsString('職員のため、直前編集でのキャンセルはできません', $body['message']);
        $this->assertSame(1, (int)$this->row(2, $date, 3)->i_change_flag);
    }

    // ---- bulkAddSubmit ----

    public function testBulkAddSubmit_personalRegistersOwnMeals(): void
    {
        $date = $this->farDate();
        $this->loginAdmin();
        $this->post('/TReservationInfo/bulk-add-submit', [
            'reservation_type' => 'personal',
            'dates' => [$date => 1],
            'meals' => [1 => [1 => 1], 3 => [1 => 1]],
        ]);

        $this->assertResponseOk();
        $body = $this->json();
        $this->assertTrue($body['ok']);
        $this->assertArrayHasKey('redirect', $body['data']);
        $this->assertNotNull($this->row(1, $date, 1));
        $this->assertNotNull($this->row(1, $date, 3));
    }

    public function testBulkAddSubmit_businessErrorsAreReturnedAsNotOk(): void
    {
        $this->loginAdmin();
        $this->post('/TReservationInfo/bulk-add-submit', ['reservation_type' => 'personal', 'meals' => [1 => [1 => 1]]]);

        $this->assertResponseOk();
        $body = $this->json();
        $this->assertFalse($body['ok']);
        $this->assertSame('日付が選択されていません。', $body['message']);
        $this->assertArrayHasKey('conflict_date', $body['data']);
    }

    public function testBulkAddSubmit_groupRegistersSelectedUsers(): void
    {
        $date = $this->farDate();
        $this->loginAdmin();
        $this->post('/TReservationInfo/bulk-add-submit', [
            'reservation_type' => 'group',
            'dates' => [$date => 1],
            'i_id_room' => 1,
            'users' => [3 => ['morning' => 1]],
        ]);

        $this->assertResponseOk();
        $this->assertTrue($this->json()['ok']);
        $this->assertNotNull($this->row(3, $date, 1));
    }

    public function testBulkAddSubmit_childIsDenied(): void
    {
        $date = $this->farDate();
        $this->loginChild();
        $this->post('/TReservationInfo/bulk-add-submit', [
            'reservation_type' => 'personal',
            'dates' => [$date => 1],
            'meals' => [1 => [1 => 1]],
        ]);

        $this->assertResponseCode(403);
        $this->assertNull($this->row(3, $date, 1));
    }
}
