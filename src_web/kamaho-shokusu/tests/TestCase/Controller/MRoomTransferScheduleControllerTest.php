<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Model\Table\MRoomTransferScheduleTable;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * MRoomTransferScheduleController 統合テスト（部屋異動予約の一覧・登録・キャンセル）
 */
class MRoomTransferScheduleControllerTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.MRoomTransferSchedule',
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TAuditLog',
    ];

    /** 管理者(user 1) か 一般職員(user 2) でログインする */
    private function login(bool $admin): void
    {
        $this->session(['Auth' => [
            'i_id_user' => $admin ? 1 : 2,
            'c_login_account' => $admin ? 'admin_user' : 'staff_user',
            'c_user_name' => $admin ? '管理者ユーザー' : '職員ユーザー',
            'i_admin' => $admin ? 1 : 0, 'i_user_level' => 0, 'i_id_room' => 1,
        ]]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
    }

    private function insertSchedule(int $status, string $date = '2030-04-01'): int
    {
        ConnectionManager::get('test')->insert('m_room_transfer_schedule', [
            'i_id_user' => 2, 'i_id_room_from' => 1, 'i_id_room_to' => 1, 'd_effective_date' => $date,
            'i_status' => $status, 'c_create_user' => 't', 'dt_create' => '2026-01-01 00:00:00',
        ]);

        return (int)$this->getTableLocator()->get('MRoomTransferSchedule')->find()->orderByDesc('i_id')->firstOrFail()->i_id;
    }

    private function statusOf(int $id): int
    {
        return (int)$this->getTableLocator()->get('MRoomTransferSchedule')->get($id)->i_status;
    }

    // ---- index ----

    public function testIndex_admin_listsAllAndFiltersByStatus(): void
    {
        $this->insertSchedule(MRoomTransferScheduleTable::STATUS_PENDING);
        $this->insertSchedule(MRoomTransferScheduleTable::STATUS_CANCELLED);
        $this->login(true);

        $this->get('/MRoomTransferSchedule');
        $this->assertResponseOk();
        $this->assertCount(2, $this->viewVariable('schedules'));

        $this->get('/MRoomTransferSchedule?status=2');
        $this->assertCount(1, $this->viewVariable('schedules'));
        $this->assertSame('2', $this->viewVariable('statusFilter'));

        // 想定外の値は絞り込みに使わない
        $this->get('/MRoomTransferSchedule?status=9');
        $this->assertCount(2, $this->viewVariable('schedules'));
    }

    public function testIndex_nonAdmin_redirectedWithFlash(): void
    {
        $this->login(false);
        $this->get('/MRoomTransferSchedule');
        $this->assertRedirectContains('/TReservationInfo');
        $this->assertFlashMessage('この機能は管理者のみ利用できます。', 'flash');
    }

    // ---- add ----

    public function testAdd_get_admin_showsFormData(): void
    {
        $this->login(true);
        $this->get('/MRoomTransferSchedule/add');
        $this->assertResponseOk();
        $this->assertArrayHasKey(2, $this->viewVariable('users'));
        // 削除済みユーザーは選択肢に出ない
        $this->assertArrayNotHasKey(6, $this->viewVariable('users'));
        $this->assertArrayHasKey(1, $this->viewVariable('rooms'));
        $this->assertSame([1], $this->viewVariable('userRoomMap')[2]);
    }

    public function testAdd_nonAdmin_redirectedToIndex(): void
    {
        $this->login(false);
        $this->post('/MRoomTransferSchedule/add', ['i_id_user' => 2, 'i_id_room_to' => 1, 'd_effective_date' => '2030-04-01']);
        $this->assertRedirectContains('/MRoomTransferSchedule');
        $this->assertFlashMessage('この機能は管理者のみ利用できます。', 'flash');
        $this->assertSame(0, $this->getTableLocator()->get('MRoomTransferSchedule')->find()->count());
    }

    public function testAdd_post_createsPendingScheduleWithActor(): void
    {
        $this->login(true);
        $this->post('/MRoomTransferSchedule/add', [
            'i_id_user' => '2', 'i_id_room_from' => '1', 'i_id_room_to' => '1', 'd_effective_date' => '2030-04-01',
        ]);

        $this->assertRedirectContains('/MRoomTransferSchedule');
        $this->assertFlashMessage('部屋異動予約を登録しました。', 'flash');
        $row = $this->getTableLocator()->get('MRoomTransferSchedule')->find()->firstOrFail();
        $this->assertSame(2, (int)$row->i_id_user);
        $this->assertSame(1, (int)$row->i_id_room_from);
        $this->assertSame(MRoomTransferScheduleTable::STATUS_PENDING, (int)$row->i_status);
        $this->assertSame('管理者ユーザー', $row->c_create_user);
    }

    public function testAdd_post_emptyFromRoomMeansNewAssignment(): void
    {
        $this->login(true);
        $this->post('/MRoomTransferSchedule/add', [
            'i_id_user' => '2', 'i_id_room_from' => '', 'i_id_room_to' => '1', 'd_effective_date' => '2030-04-01',
        ]);

        $row = $this->getTableLocator()->get('MRoomTransferSchedule')->find()->firstOrFail();
        $this->assertNull($row->i_id_room_from);
    }

    /** @return array<string, array{0:array<string,string>}> */
    public static function missingRequired(): array
    {
        return [
            'no user' => [['i_id_room_to' => '1', 'd_effective_date' => '2030-04-01']],
            'no destination room' => [['i_id_user' => '2', 'd_effective_date' => '2030-04-01']],
            'no date' => [['i_id_user' => '2', 'i_id_room_to' => '1', 'd_effective_date' => '']],
        ];
    }

    /**
     * @param array<string,string> $data
     * @dataProvider missingRequired
     */
    public function testAdd_post_missingRequired_showsErrorAndSavesNothing(array $data): void
    {
        $this->login(true);
        $this->post('/MRoomTransferSchedule/add', $data);

        $this->assertResponseOk();
        $this->assertResponseContains('ユーザー・異動先部屋・有効開始日は必須です。');
        $this->assertSame(0, $this->getTableLocator()->get('MRoomTransferSchedule')->find()->count());
    }

    public function testAdd_post_invalidDate_showsValidationError(): void
    {
        $this->login(true);
        $this->post('/MRoomTransferSchedule/add', ['i_id_user' => '2', 'i_id_room_to' => '1', 'd_effective_date' => 'not-a-date']);

        $this->assertResponseOk();
        $this->assertResponseContains('alert-danger');
        $this->assertSame(0, $this->getTableLocator()->get('MRoomTransferSchedule')->find()->count());
    }

    public function testAdd_post_dbFailure_showsErrorAndSavesNothing(): void
    {
        $conn = ConnectionManager::get('test');
        $this->login(true);
        // 保存時に DB 例外（RuntimeException 以外）を起こす
                $conn->execute("CREATE TRIGGER trg_fail_insert BEFORE INSERT ON m_room_transfer_schedule BEGIN SELECT RAISE(ABORT, 'boom'); END");
        try {
            $this->post('/MRoomTransferSchedule/add', ['i_id_user' => '2', 'i_id_room_to' => '1', 'd_effective_date' => '2030-04-01']);
        } finally {
            $conn->execute('DROP TRIGGER trg_fail_insert');
        }
        $this->assertResponseContains('alert-danger');
        $this->assertSame(0, $this->getTableLocator()->get('MRoomTransferSchedule')->find()->count());
    }

    // ---- cancel ----

    public function testCancel_pending_becomesCancelled(): void
    {
        $id = $this->insertSchedule(MRoomTransferScheduleTable::STATUS_PENDING);
        $this->login(true);
        $this->post("/MRoomTransferSchedule/cancel/{$id}");

        $this->assertRedirectContains('/MRoomTransferSchedule');
        $this->assertFlashMessage('異動予約をキャンセルしました。', 'flash');
        $this->assertSame(MRoomTransferScheduleTable::STATUS_CANCELLED, $this->statusOf($id));
    }

    public function testCancel_alreadyApplied_isRejectedAndUnchanged(): void
    {
        $id = $this->insertSchedule(MRoomTransferScheduleTable::STATUS_APPLIED);
        $this->login(true);
        $this->post("/MRoomTransferSchedule/cancel/{$id}");

        $this->assertFlashMessage('キャンセルできるのは予約中のスケジュールのみです。', 'flash');
        $this->assertSame(MRoomTransferScheduleTable::STATUS_APPLIED, $this->statusOf($id));
    }

    public function testCancel_unknownId_returns404(): void
    {
        $this->login(true);
        $this->post('/MRoomTransferSchedule/cancel/99999');
        $this->assertResponseCode(404);
    }

    public function testCancel_nonAdmin_cannotCancel(): void
    {
        $id = $this->insertSchedule(MRoomTransferScheduleTable::STATUS_PENDING);
        $this->login(false);
        $this->post("/MRoomTransferSchedule/cancel/{$id}");

        $this->assertRedirectContains('/MRoomTransferSchedule');
        $this->assertFlashMessage('この機能は管理者のみ利用できます。', 'flash');
        $this->assertSame(MRoomTransferScheduleTable::STATUS_PENDING, $this->statusOf($id));
    }

    public function testCancel_get_isNotAllowed(): void
    {
        $id = $this->insertSchedule(MRoomTransferScheduleTable::STATUS_PENDING);
        $this->login(true);
        $this->get("/MRoomTransferSchedule/cancel/{$id}");
        $this->assertResponseError();
        $this->assertSame(MRoomTransferScheduleTable::STATUS_PENDING, $this->statusOf($id));
    }
}
