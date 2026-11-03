<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * ApprovalController の統合テスト（承認画面・承認/差し戻し API・反映・履歴）。
 *
 * ログインユーザー（セッション）:
 *   1 = 管理者(i_admin=1) / 2 = ブロック長(i_admin=2) / 3 = 一般職員(i_admin=0)
 * ユーザー1,2,3 はブロック1所属。ユーザー1,2 を大人(i_user_level=7)として承認対象に出す。
 */
class ApprovalControllerTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.TIndividualReservationInfo',
        'app.TReservationInfo',
        'app.TApprovalLog',
        'app.MRoomInfo',
        'app.MUserInfo',
        'app.MUserGroup',
        'app.TAuditLog',
    ];

    private mixed $originalFlag = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->originalFlag = Configure::read('Features.approval');
        Configure::write('Features.approval', true);

        $this->getTableLocator()->get('MUserInfo')->updateAll(['i_user_level' => 7], ['i_id_user IN' => [1, 2]]);
        // ログインユーザーの権限は DB の値が使われるため、ユーザー2 をブロック長にする
        $this->getTableLocator()->get('MUserInfo')->updateAll(['i_admin' => 2], ['i_id_user' => 2]);
        $this->getTableLocator()->get('TNotification')->deleteAll([]);
        // ユーザー2(ブロック長) の予約と、ユーザー1(管理者) の予約を未承認で追加（ユーザー1の 09-07 朝はフィクスチャ）
        $this->addReservation(2, '2024-09-07', 1);
        $this->addReservation(2, '2024-09-14', 3);
    }

    protected function tearDown(): void
    {
        $this->getTableLocator()->get('TNotification')->deleteAll([]);
        Configure::write('Features.approval', $this->originalFlag);
        parent::tearDown();
    }

    private function addReservation(int $userId, string $date, int $type, int $status = 0, int $roomId = 1): void
    {
        $table = $this->getTableLocator()->get('TIndividualReservationInfo');
        $table->saveOrFail($table->newEntity([
            'i_id_user' => $userId,
            'd_reservation_date' => $date,
            'i_id_room' => $roomId,
            'i_reservation_type' => $type,
            'eat_flag' => 1,
            'dt_create' => '2024-09-01 00:00:00',
            'c_create_user' => 't',
            'dt_update' => '2024-09-01 00:00:00',
            'c_update_user' => 't',
        ]));
        $table->updateAll(['i_approval_status' => $status], [
            'i_id_user' => $userId,
            'd_reservation_date' => $date,
            'i_id_room' => $roomId,
            'i_reservation_type' => $type,
        ]);
    }

    private function approvalStatus(int $userId, string $date, int $type, int $roomId = 1): int
    {
        return (int)$this->getTableLocator()->get('TIndividualReservationInfo')->find()
            ->where(['i_id_user' => $userId, 'd_reservation_date' => $date, 'i_reservation_type' => $type, 'i_id_room' => $roomId])
            ->firstOrFail()
            ->i_approval_status;
    }

    private function login(int $userId): void
    {
        $iAdmin = [1 => 1, 2 => 2, 3 => 0][$userId];
        $this->session([
            'Auth' => [
                'i_id_user' => $userId,
                'c_login_account' => [1 => 'admin_user', 2 => 'staff_user', 3 => 'non_staff_user'][$userId],
                'c_user_name' => 'ユーザー' . $userId,
                'i_admin' => $iAdmin,
                'i_user_level' => 7,
                'i_id_room' => 1,
            ],
        ]);
        $this->enableCsrfToken();
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        return (array)json_decode((string)$this->_response->getBody(), true);
    }

    /**
     * @return array{i_id_user: int, d_reservation_date: string, i_id_room: int, i_reservation_type: int}
     */
    private function key(int $userId, string $date, int $type, int $roomId = 1): array
    {
        return ['i_id_user' => $userId, 'd_reservation_date' => $date, 'i_id_room' => $roomId, 'i_reservation_type' => $type];
    }

    /**
     * ブロック長(ユーザー2)の担当外の部屋を用意し、ユーザー1の未承認予約(朝)をそれぞれに置く（日付は outDate()）。
     *   2 = 所属なし / 3 = 退出済み(active_flag=1) / 4 = 所属中だが部屋が削除済み
     *
     * @return list<int> 担当外の部屋ID
     */
    private function addOutOfScopeRooms(): array
    {
        $rooms = $this->getTableLocator()->get('MRoomInfo');
        $groups = $this->getTableLocator()->get('MUserGroup');
        foreach ([2 => 0, 3 => 0, 4 => 1] as $roomId => $deleted) {
            $rooms->saveOrFail($rooms->newEntity([
                'i_id_room' => $roomId, 'c_room_name' => '担当外' . $roomId, 'i_disp_no' => $roomId, 'i_enable' => 1, 'i_del_flg' => $deleted,
                'dt_create' => '2024-09-01 00:00:00', 'c_create_user' => 't', 'dt_update' => '2024-09-01 00:00:00', 'c_update_user' => 't',
            ], ['accessibleFields' => ['*' => true]]));
            $this->addReservation(1, $this->outDate($roomId), 1, 0, $roomId);
        }
        foreach ([3 => 1, 4 => 0] as $roomId => $activeFlag) {
            $groups->saveOrFail($groups->newEntity([
                'i_id_user' => 2, 'i_id_room' => $roomId, 'active_flag' => $activeFlag,
                'dt_create' => '2024-09-01 00:00:00', 'c_create_user' => 't', 'dt_update' => '2024-09-01 00:00:00', 'c_update_user' => 't',
            ]));
        }

        return [2, 3, 4];
    }

    /** 同じ人・日・食種は1部屋にしか予約できないため、担当外の部屋ごとに日をずらす */
    private function outDate(int $roomId): string
    {
        return '2024-09-2' . $roomId;
    }

    /**
     * @return array{target_id: string, count: int}
     */
    private function lastAudit(string $action): array
    {
        $row = $this->getTableLocator()->get('TAuditLog')->find()
            ->where(['c_action' => $action])
            ->orderBy(['i_id_audit' => 'DESC'])
            ->firstOrFail();

        return ['target_id' => (string)$row->c_target_id, 'count' => (int)((json_decode((string)$row->c_detail, true) ?? [])['count'] ?? 0)];
    }

    // ----------------------------------------------------------------- index

    public function testIndex_redirectsByRole(): void
    {
        $this->login(1);
        $this->get('/Approval');
        $this->assertRedirectContains('/Approval/adminIndex');

        $this->login(2);
        $this->get('/Approval');
        $this->assertRedirectContains('/Approval/blockLeaderIndex');
    }

    public function testIndex_generalStaff_isRejectedWithFlash(): void
    {
        $this->login(3);
        $this->get('/Approval');
        $this->assertRedirectContains('/pages/home');
        $this->assertFlashMessage('この画面を表示する権限がありません。', 'flash');
    }

    // ------------------------------------------------------------ list pages

    public function testBlockLeaderIndex_showsPendingAdultRecordsOfOwnRooms(): void
    {
        $this->login(2);

        $this->get('/Approval/blockLeaderIndex?date_from=2024-09-01&date_to=2024-09-30');

        $this->assertResponseOk();
        $this->assertResponseContains('ユーザー');
    }

    public function testBlockLeaderIndex_acceptsStatusAndRoomFilters(): void
    {
        $this->login(2);

        $this->get('/Approval/blockLeaderIndex?room_id=1&status=2&date_from=2024-09-01&date_to=2024-09-30');
        $this->assertResponseOk();

        $this->get('/Approval/blockLeaderIndex');
        $this->assertResponseOk();
    }

    public function testBlockLeaderIndex_generalStaff_isForbidden(): void
    {
        $this->login(3);
        $this->get('/Approval/blockLeaderIndex');
        $this->assertResponseCode(403);
    }

    public function testAdminIndex_admin_ok_blockLeader_forbidden(): void
    {
        $this->login(1);
        $this->get('/Approval/adminIndex?date_from=2024-09-01&date_to=2024-09-30&status=0&room_id=1');
        $this->assertResponseOk();

        $this->get('/Approval/adminIndex');
        $this->assertResponseOk();

        $this->login(2);
        $this->get('/Approval/adminIndex');
        $this->assertResponseCode(403);
    }

    // ------------------------------------------------------ block leader POST

    public function testBlockLeaderApprove_approvesOthersButNotOwnReservation(): void
    {
        $this->login(2);

        $this->post('/Approval/blockLeaderApprove', ['keys' => [
            $this->key(1, '2024-09-07', 1),
            $this->key(2, '2024-09-07', 1),
        ]]);

        $this->assertResponseOk();
        $this->assertSame(['success' => true], $this->json());
        $this->assertSame(1, $this->approvalStatus(1, '2024-09-07', 1));
        $this->assertSame(0, $this->approvalStatus(2, '2024-09-07', 1), '自己承認は防止される');
    }

    public function testBlockLeaderApprove_onlyOwnReservation_reportsNotSuccessful(): void
    {
        $this->login(2);

        $this->post('/Approval/blockLeaderApprove', ['keys' => [$this->key(2, '2024-09-14', 3)]]);

        $this->assertResponseOk();
        $this->assertSame(['success' => false], $this->json());
        $this->assertSame(0, $this->approvalStatus(2, '2024-09-14', 3));
    }

    /** #989 不具合2: 担当外（所属なし・退出済み・削除済みの部屋）の予約は承認されない */
    public function testBlockLeaderApprove_outOfScopeRooms_areNotApproved(): void
    {
        $outOfScope = $this->addOutOfScopeRooms();
        $this->login(2);

        $this->post('/Approval/blockLeaderApprove', ['keys' => array_map(fn(int $r) => $this->key(1, $this->outDate($r), 1, $r), $outOfScope)]);

        $this->assertResponseOk();
        $this->assertSame(['success' => false], $this->json());
        foreach ($outOfScope as $roomId) {
            $this->assertSame(0, $this->approvalStatus(1, $this->outDate($roomId), 1, $roomId), "部屋{$roomId}は担当外");
        }
        $this->assertSame(0, $this->getTableLocator()->get('TApprovalLog')->find()->count());
    }

    /** #989 不具合2: 担当部屋と担当外を混ぜると、担当部屋の分だけ承認され、件数・監査ログも担当部屋の分だけ */
    public function testBlockLeaderApprove_mixedKeys_approvesOnlyOwnRooms(): void
    {
        $this->addOutOfScopeRooms();
        $this->login(2);

        $this->post('/Approval/blockLeaderApprove', ['keys' => [
            $this->key(1, '2024-09-07', 1),
            $this->key(1, $this->outDate(2), 1, 2),
        ]]);

        $this->assertSame(['success' => true], $this->json());
        $this->assertSame(1, $this->approvalStatus(1, '2024-09-07', 1));
        $this->assertSame(0, $this->approvalStatus(1, $this->outDate(2), 1, 2));
        $this->assertSame(['target_id' => '1:2024-09-07', 'count' => 1], $this->lastAudit('approval_block_leader'));
        $this->assertSame(1, $this->getTableLocator()->get('TApprovalLog')->find()->count());
    }

    /** #989 不具合2: 管理者は従来どおり全部屋を承認できる */
    public function testBlockLeaderApprove_admin_canApproveAnyRoom(): void
    {
        $this->addOutOfScopeRooms();
        $this->addReservation(2, '2024-09-21', 1, 0, 2);
        $this->login(1);

        $this->post('/Approval/blockLeaderApprove', ['keys' => [$this->key(2, '2024-09-21', 1, 2)]]);

        $this->assertSame(['success' => true], $this->json());
        $this->assertSame(1, $this->approvalStatus(2, '2024-09-21', 1, 2));
    }

    public function testBlockLeaderApprove_emptyKeys_returns400(): void
    {
        $this->login(2);

        $this->post('/Approval/blockLeaderApprove', ['keys' => []]);

        $this->assertResponseCode(400);
        $this->assertSame(['success' => false, 'error' => '対象が指定されていません'], $this->json());
    }

    public function testBlockLeaderApprove_malformedKeys_returns500(): void
    {
        $this->login(2);

        $this->post('/Approval/blockLeaderApprove', ['keys' => [['unexpected' => 1]]]);

        $this->assertResponseCode(500);
        $this->assertSame('承認処理中にエラーが発生しました。', $this->json()['error']);
    }

    public function testBlockLeaderApprove_generalStaff_isForbidden(): void
    {
        $this->login(3);

        $this->post('/Approval/blockLeaderApprove', ['keys' => [$this->key(1, '2024-09-07', 1)]]);

        $this->assertResponseCode(403);
        $this->assertSame(0, $this->approvalStatus(1, '2024-09-07', 1));
    }

    public function testBlockLeaderApprove_getMethod_isNotAllowed(): void
    {
        $this->login(2);
        $this->get('/Approval/blockLeaderApprove');
        $this->assertResponseCode(405);
    }

    public function testBlockLeaderReject_rejectsOthersAndNotifiesUser(): void
    {
        $this->login(2);

        $this->post('/Approval/blockLeaderReject', [
            'keys' => [$this->key(1, '2024-09-07', 1), $this->key(2, '2024-09-07', 1)],
            'reason' => '人数が合いません',
        ]);

        $this->assertResponseOk();
        $this->assertSame(['success' => true], $this->json());
        $this->assertSame(3, $this->approvalStatus(1, '2024-09-07', 1));
        $this->assertSame(0, $this->approvalStatus(2, '2024-09-07', 1), '自分の予約は差し戻せない');
        $notice = $this->getTableLocator()->get('TNotification')->find()->firstOrFail();
        $this->assertSame(1, (int)$notice->i_id_user);
        $this->assertStringContainsString('人数が合いません', $notice->c_message);
    }

    /** #989 不具合2: 差し戻しも担当部屋の分だけ。担当外は状態・通知・件数に入らない */
    public function testBlockLeaderReject_mixedKeys_rejectsOnlyOwnRooms(): void
    {
        $outOfScope = $this->addOutOfScopeRooms();
        $this->login(2);

        $this->post('/Approval/blockLeaderReject', [
            'keys' => [$this->key(1, '2024-09-07', 1), ...array_map(fn(int $r) => $this->key(1, $this->outDate($r), 1, $r), $outOfScope)],
            'reason' => 'x',
        ]);

        $this->assertSame(['success' => true], $this->json());
        $this->assertSame(3, $this->approvalStatus(1, '2024-09-07', 1));
        foreach ($outOfScope as $roomId) {
            $this->assertSame(0, $this->approvalStatus(1, $this->outDate($roomId), 1, $roomId), "部屋{$roomId}は担当外");
        }
        $this->assertSame(['target_id' => '1:2024-09-07', 'count' => 1], $this->lastAudit('approval_rejected'));
        $this->assertSame(1, $this->getTableLocator()->get('TNotification')->find()->count());
    }

    /** #989 不具合2: 担当外だけを差し戻そうとしても変わらない */
    public function testBlockLeaderReject_outOfScopeOnly_reportsNotSuccessful(): void
    {
        $this->addOutOfScopeRooms();
        $this->login(2);

        $this->post('/Approval/blockLeaderReject', ['keys' => [$this->key(1, $this->outDate(2), 1, 2)], 'reason' => 'x']);

        $this->assertSame(['success' => false], $this->json());
        $this->assertSame(0, $this->approvalStatus(1, $this->outDate(2), 1, 2));
    }

    public function testBlockLeaderReject_emptyKeys_returns400(): void
    {
        $this->login(2);
        $this->post('/Approval/blockLeaderReject', ['reason' => 'x']);
        $this->assertResponseCode(400);
    }

    public function testBlockLeaderReject_malformedKeys_returns500(): void
    {
        $this->login(2);
        $this->post('/Approval/blockLeaderReject', ['keys' => [['unexpected' => 1]]]);
        $this->assertResponseCode(500);
        $this->assertSame('却下処理中にエラーが発生しました。', $this->json()['error']);
    }

    // ----------------------------------------------------------- admin POST

    public function testAdminApprove_approvesIncludingOwnAndReflectsToReservation(): void
    {
        $this->login(1);

        $this->post('/Approval/adminApprove', ['keys' => [
            $this->key(1, '2024-09-07', 1),
            $this->key(2, '2024-09-07', 1),
        ]]);

        $this->assertResponseOk();
        $this->assertSame(['success' => true], $this->json());
        $this->assertSame(2, $this->approvalStatus(1, '2024-09-07', 1), '管理者は自分の予約も最終承認できる');
        $this->assertSame(2, $this->approvalStatus(2, '2024-09-07', 1));
        $reflected = $this->getTableLocator()->get('TReservationInfo')->find()
            ->where(['d_reservation_date' => '2024-09-07', 'c_reservation_type' => 1])
            ->firstOrFail();
        $this->assertSame(2, (int)$reflected->i_taberu_ninzuu);
    }

    public function testAdminApprove_alreadyApproved_reportsNotSuccessful(): void
    {
        $this->addReservation(2, '2024-09-21', 1, 2);
        $this->login(1);

        $this->post('/Approval/adminApprove', ['keys' => [$this->key(2, '2024-09-21', 1)]]);

        $this->assertSame(['success' => false], $this->json());
    }

    public function testAdminApprove_validationAndErrors(): void
    {
        $this->login(1);
        $this->post('/Approval/adminApprove', ['keys' => []]);
        $this->assertResponseCode(400);

        $this->post('/Approval/adminApprove', ['keys' => [['unexpected' => 1]]]);
        $this->assertResponseCode(500);
        $this->assertSame('承認処理中にエラーが発生しました。', $this->json()['error']);
    }

    public function testAdminApprove_blockLeader_isForbidden(): void
    {
        $this->login(2);
        $this->post('/Approval/adminApprove', ['keys' => [$this->key(1, '2024-09-07', 1)]]);
        $this->assertResponseCode(403);
        $this->assertSame(0, $this->approvalStatus(1, '2024-09-07', 1));
    }

    public function testAdminReject_rejectsIncludingOwnReservation(): void
    {
        $this->login(1);

        $this->post('/Approval/adminReject', [
            'keys' => [$this->key(1, '2024-09-07', 1)],
            'reason' => '重複しています',
        ]);

        $this->assertSame(['success' => true], $this->json());
        $this->assertSame(3, $this->approvalStatus(1, '2024-09-07', 1));
        $log = $this->getTableLocator()->get('TApprovalLog')->find()->firstOrFail();
        $this->assertSame('重複しています', $log->c_reject_reason);
    }

    public function testAdminReject_validationAndErrors(): void
    {
        $this->login(1);
        $this->post('/Approval/adminReject', ['reason' => 'x']);
        $this->assertResponseCode(400);

        $this->post('/Approval/adminReject', ['keys' => [['unexpected' => 1]]]);
        $this->assertResponseCode(500);
        $this->assertSame('却下処理中にエラーが発生しました。', $this->json()['error']);
    }

    public function testAdminReject_blockLeader_isForbidden(): void
    {
        $this->login(2);
        $this->post('/Approval/adminReject', ['keys' => [$this->key(1, '2024-09-07', 1)]]);
        $this->assertResponseCode(403);
    }

    public function testAdminReflect_reflectsFinalApprovedRecords(): void
    {
        $this->addReservation(2, '2024-09-21', 1, 2);
        $this->login(1);

        $this->post('/Approval/adminReflect', ['room_id' => 1, 'date_from' => '2024-09-21', 'date_to' => '2024-09-21']);

        $this->assertResponseOk();
        $this->assertSame(['success' => true, 'reflected_count' => 1, 'group_count' => 1], $this->json());
        $this->assertTrue($this->getTableLocator()->get('TReservationInfo')->exists(['d_reservation_date' => '2024-09-21']));
    }

    public function testAdminReflect_withoutFilters_returnsZeroWhenNothingApproved(): void
    {
        $this->login(1);

        $this->post('/Approval/adminReflect', []);

        $this->assertSame(['success' => true, 'reflected_count' => 0, 'group_count' => 0], $this->json());
    }

    public function testAdminReflect_blockLeader_isForbidden(): void
    {
        $this->login(2);
        $this->post('/Approval/adminReflect', []);
        $this->assertResponseCode(403);
    }

    // ------------------------------------------------------------------- log

    public function testApprovalLog_admin_seesAllLogs(): void
    {
        $this->login(1);
        $this->post('/Approval/adminApprove', ['keys' => [$this->key(2, '2024-09-07', 1)]]);

        $this->get('/Approval/approvalLog');

        $this->assertResponseOk();
    }

    public function testApprovalLog_blockLeader_seesOwnRoomLogs(): void
    {
        $this->login(2);
        $this->post('/Approval/blockLeaderApprove', ['keys' => [$this->key(1, '2024-09-07', 1)]]);

        $this->get('/Approval/approvalLog');

        $this->assertResponseOk();
    }

    public function testApprovalLog_generalStaff_isRedirected(): void
    {
        $this->login(3);

        $this->get('/Approval/approvalLog');

        $this->assertRedirectContains('/pages/home');
        $this->assertFlashMessage('この画面を表示する権限がありません。', 'flash');
    }
}
