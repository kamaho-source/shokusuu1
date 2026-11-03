<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Authentication\PasswordHasher\DefaultPasswordHasher;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * MUserInfoController の既存テストで触れていない操作（ログイン/ログアウト、権限更新API、
 * パスワード変更、削除/復元、一括登録、所属部屋API、一覧の絞り込み）のテスト。
 *
 * ユーザー: 1=管理者(i_admin=1) / 2=職員 / 3=子ども(i_user_level=1) / 4=システム管理者(i_admin=3) / 6=削除済み
 * 権限はセッションではなく DB の値で判定されるため、ログイン時に DB 側も合わせる。
 */
class MUserInfoControllerActionsTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TAuditLog',
    ];

    private const ACCOUNTS = [
        1 => 'admin_user',
        2 => 'staff_user',
        3 => 'non_staff_user',
        4 => 'system_admin_user',
        6 => 'deleted_user',
    ];

    private function login(int $userId, ?int $iAdmin = null): void
    {
        $db = ConnectionManager::get('test');
        if ($iAdmin !== null) {
            $db->update('m_user_info', ['i_admin' => $iAdmin], ['i_id_user' => $userId]);
        }
        $row = $db->execute('SELECT i_admin, i_user_level FROM m_user_info WHERE i_id_user = ?', [$userId])->fetch('assoc');
        $this->session(['Auth' => [
            'i_id_user' => $userId,
            'c_login_account' => self::ACCOUNTS[$userId],
            'c_user_name' => 'ユーザー' . $userId,
            'i_admin' => (int)$row['i_admin'],
            'i_user_level' => (int)$row['i_user_level'],
            'i_id_room' => 1,
        ]]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
    }

    /**
     * @return array<string, mixed>
     */
    private function json(): array
    {
        return (array)json_decode((string)$this->_response->getBody(), true);
    }

    private function userField(int $userId, string $field): mixed
    {
        return $this->getTableLocator()->get('MUserInfo')->get($userId)->get($field);
    }

    private function auditCount(string $action): int
    {
        return $this->getTableLocator()->get('TAuditLog')->find()->where(['c_action' => $action])->count();
    }

    /**
     * フィクスチャに既存の監査ログがあるため、操作前後の増分で確認する。
     */
    private function auditCountBefore(string $action): int
    {
        return $this->auditCount($action);
    }

    // ------------------------------------------------------------ 一覧

    public function testIndex_nonAdmin_seesOnlyOwnRecordAndCannotListDeleted(): void
    {
        $this->login(2, 0);

        $this->get('/MUserInfo?show_deleted=1');

        $this->assertResponseOk();
        $this->assertSame([2], array_map(fn($u) => $u->i_id_user, (array)$this->viewVariable('mUserInfo')->toArray()));
        $this->assertFalse($this->viewVariable('showDeleted'));
    }

    public function testIndex_admin_canListDeletedUsers(): void
    {
        $this->login(1);

        $this->get('/MUserInfo?show_deleted=1');

        $this->assertResponseOk();
        $this->assertSame([6], array_map(fn($u) => $u->i_id_user, (array)$this->viewVariable('mUserInfo')->toArray()));
        $this->assertTrue($this->viewVariable('showDeleted'));
    }

    // ------------------------------------------------- 一括登録フォーム/JSON

    public function testImportForm_admin_ok_child_redirected(): void
    {
        $this->login(1);
        $this->get('/m-user-info/import-form');
        $this->assertResponseOk();

        $this->login(3, 0);
        $this->get('/m-user-info/import-form');
        // 一括登録は UserImportController に分けたので、行き先（利用者一覧）はコントローラー名を付けて書く（#989）
        $this->assertRedirect(['controller' => 'MUserInfo', 'action' => 'index']);
        $this->assertFlashMessage('この機能を利用する権限がありません。', 'flash');
    }

    public function testImport_legacyEndpoint_isGone(): void
    {
        $this->login(1);
        $this->post('/m-user-info/import', []);
        $this->assertResponseCode(410);
    }

    /**
     * フォームではなく生のボディ（JSON文字列）で送る。CSRF トークンはヘッダーで渡す。
     */
    private function postRawBody(string $url, string $body): void
    {
        $token = (new \Cake\Http\Middleware\CsrfProtectionMiddleware())->createToken();
        $this->configRequest([
            'headers' => ['Content-Type' => 'text/plain', 'X-CSRF-Token' => $token],
            'cookies' => ['csrfToken' => $token],
        ]);
        $this->post($url, $body);
    }

    public function testImportJson_acceptsRawJsonBody(): void
    {
        $this->login(1);
        $this->_csrfToken = false;

        $this->postRawBody('/MUserInfo/import-json', json_encode(['records' => [
            ['_row' => 1, 'login_id' => 'raw_child', 'name' => '生JSON児童', 'role' => '児童', 'room_name1' => 'Lorem ipsum dolor sit amet'],
        ]]) ?: '');

        $this->assertResponseOk();
        $this->assertTrue($this->json()['ok']);
        $this->assertTrue($this->getTableLocator()->get('MUserInfo')->exists(['c_login_account' => 'raw_child']));
    }

    public function testImportJson_malformedJson_returns400(): void
    {
        $this->login(1);
        $this->_csrfToken = false;

        $this->postRawBody('/MUserInfo/import-json', '{not json');

        $this->assertResponseCode(400);
    }

    public function testImportJson_withoutRecords_returns400(): void
    {
        $this->login(1);
        $this->_csrfToken = false;

        $this->postRawBody('/MUserInfo/import-json', json_encode(['foo' => 1]) ?: '');

        $this->assertResponseCode(400);
    }

    public function testImportJson_nonArrayRecords_returns400(): void
    {
        $this->login(1);

        $this->post('/MUserInfo/import-json', ['records' => 'abc']);

        $this->assertResponseCode(400);
    }

    public function testImportJson_nonRecordRows_areReportedPerRowNotCreated(): void
    {
        $this->login(1);
        $before = $this->getTableLocator()->get('MUserInfo')->find()->count();

        $this->post('/MUserInfo/import-json', ['records' => ['not-a-record']]);

        $this->assertResponseOk();
        $this->assertSame($before, $this->getTableLocator()->get('MUserInfo')->find()->count());
    }

    // --------------------------------------------------- 管理者権限更新API

    public function testUpdateAdminStatus_nonAdmin_isForbidden(): void
    {
        $this->login(2, 0);

        $this->post('/MUserInfo/update-admin-status', ['i_id_user' => 3, 'i_admin' => 1]);

        $this->assertResponseCode(403);
        $this->assertSame(0, (int)$this->userField(3, 'i_admin'));
    }

    public function testUpdateAdminStatus_admin_promotesGeneralUser(): void
    {
        $this->login(1);

        $this->post('/MUserInfo/update-admin-status', ['i_id_user' => 3, 'i_admin' => 1]);

        $this->assertResponseOk();
        $this->assertSame(1, (int)$this->userField(3, 'i_admin'));
    }

    public function testUpdateAdminStatus_changingFromAnotherRole_needsConfirmation(): void
    {
        $this->getTableLocator()->get('MUserInfo')->updateAll(['i_admin' => 2], ['i_id_user' => 3]);
        $this->login(1);

        $this->post('/MUserInfo/update-admin-status', ['i_id_user' => 3, 'i_admin' => 1]);
        $this->assertResponseCode(409);
        $this->assertSame(2, (int)$this->userField(3, 'i_admin'));

        $this->post('/MUserInfo/update-admin-status', ['i_id_user' => 3, 'i_admin' => 1, 'overwrite' => 'true']);
        $this->assertResponseOk();
        $this->assertSame(1, (int)$this->userField(3, 'i_admin'));
    }

    // ----------------------------------------------- ブロック長権限更新API

    public function testUpdateUserLevel_nonAdminIsForbidden(): void
    {
        $this->login(2, 0);
        $this->post('/MUserInfo/update-user-level', ['i_id_user' => 3, 'i_admin' => 2]);
        $this->assertResponseCode(403);
        $this->assertSame(0, (int)$this->userField(3, 'i_admin'));
    }

    public function testUpdateUserLevel_setsBlockLeaderWithConflictCheck(): void
    {
        $this->login(1);

        $this->post('/MUserInfo/update-user-level', ['i_id_user' => 3, 'i_admin' => 2]);
        $this->assertResponseOk();
        $this->assertSame(2, (int)$this->userField(3, 'i_admin'));

        // 別の権限(管理者)からの変更は、確認なしでは拒否される
        $this->getTableLocator()->get('MUserInfo')->updateAll(['i_admin' => 1], ['i_id_user' => 3]);
        $this->post('/MUserInfo/update-user-level', ['i_id_user' => 3, 'i_admin' => 2]);
        $this->assertResponseCode(409);
        $this->assertSame(1, (int)$this->userField(3, 'i_admin'));
    }

    // --------------------------------------------- システム管理者権限更新API

    public function testUpdateSystemAdminStatus_adminIsForbidden(): void
    {
        $this->login(1);
        $this->post('/MUserInfo/update-system-admin-status', ['i_id_user' => 3, 'i_system_admin' => 1]);
        $this->assertResponseCode(403);
        $this->assertSame(0, (int)$this->userField(3, 'i_admin'));
    }

    public function testUpdateSystemAdminStatus_revokeAndConflict(): void
    {
        $this->getTableLocator()->get('MUserInfo')->updateAll(['i_admin' => 3], ['i_id_user' => 3]);
        $this->login(4);

        $this->post('/MUserInfo/update-system-admin-status', ['i_id_user' => 3, 'i_system_admin' => 0]);
        $this->assertResponseOk();
        $this->assertSame(0, (int)$this->userField(3, 'i_admin'));

        // 管理者(1)のユーザーに対し、システム管理者の「解除」は別権限のため拒否される
        $this->getTableLocator()->get('MUserInfo')->updateAll(['i_admin' => 1], ['i_id_user' => 3]);
        $this->post('/MUserInfo/update-system-admin-status', ['i_id_user' => 3, 'i_system_admin' => 0]);
        $this->assertResponseCode(409);
        $this->assertSame(1, (int)$this->userField(3, 'i_admin'));
    }

    // ------------------------------------------------ レポート閲覧権限API

    public function testUpdateReportAccess_systemAdminGrantsAndRevokes(): void
    {
        $auditBefore = $this->auditCount('user_report_access_change');
        $this->login(4);

        $this->post('/MUserInfo/update-report-access', ['i_id_user' => 3, 'i_report_access' => 1]);
        $this->assertResponseOk();
        $this->assertSame(1, (int)$this->userField(3, 'i_report_access'));
        $this->assertSame($auditBefore + 1, $this->auditCount('user_report_access_change'));

        $this->post('/MUserInfo/update-report-access', ['i_id_user' => 3, 'i_report_access' => 0]);
        $this->assertResponseOk();
        $this->assertSame(0, (int)$this->userField(3, 'i_report_access'));
    }

    public function testUpdateReportAccess_adminIsForbidden(): void
    {
        $this->login(1);
        $this->post('/MUserInfo/update-report-access', ['i_id_user' => 3, 'i_report_access' => 1]);
        $this->assertResponseCode(403);
        $this->assertSame(0, (int)$this->userField(3, 'i_report_access'));
    }

    // ------------------------------------------------------ 詳細/削除/復元

    public function testView_staffCannotViewOtherUser(): void
    {
        $this->login(2, 0);

        $this->get('/MUserInfo/view/1');

        $this->assertRedirect(['action' => 'index']);
        $this->assertFlashMessage('あなたは閲覧権限がありません。', 'flash');
    }

    public function testDelete_nonAdminIsRejectedAndAdminSoftDeletes(): void
    {
        $this->login(2, 0);
        $this->post('/MUserInfo/delete/3');
        $this->assertRedirect(['action' => 'index']);
        $this->assertFlashMessage('あなたは削除権限がありません。', 'flash');
        $this->assertSame(0, (int)$this->userField(3, 'i_del_flag'));

        $this->login(1);
        $this->post('/MUserInfo/delete/3');
        $this->assertRedirect(['action' => 'index']);
        $this->assertFlashMessage('ユーザー情報が削除されました。', 'flash');
        $this->assertSame(1, (int)$this->userField(3, 'i_del_flag'));
    }

    public function testDelete_getMethod_isNotAllowed(): void
    {
        $this->login(1);
        $this->get('/MUserInfo/delete/3');
        $this->assertResponseCode(405);
    }

    public function testRestore_activeUserCannotBeRestored(): void
    {
        $this->login(1);

        $this->post('/MUserInfo/restore/3');

        $this->assertRedirectContains('show_deleted=1');
        $this->assertFlashMessage('このユーザーは削除されていません。', 'flash');
    }

    public function testRestore_adminRestoresDeletedUser(): void
    {
        $this->login(1);

        $this->post('/MUserInfo/restore/6');

        $this->assertRedirect(['action' => 'index']);
        $this->assertFlashMessage('ユーザー「削除済みユーザー」を復元しました。', 'flash');
        $this->assertSame(0, (int)$this->userField(6, 'i_del_flag'));
    }

    /**
     * 削除済みの利用者 6 に、削除で外した部屋 70（印あり）と、部屋異動で外れた部屋 71（印なし）を持たせる。
     */
    private function seedDeletedUserRooms(): void
    {
        $db = ConnectionManager::get('test');
        $db->delete('m_user_group', ['i_id_user' => 6]);
        foreach ([70 => '削除前の部屋', 71 => '異動で外れた部屋'] as $id => $name) {
            $db->insert('m_room_info', ['i_id_room' => $id, 'c_room_name' => $name, 'i_disp_no' => $id, 'i_del_flg' => 0]);
        }
        $db->insert('m_user_group', ['i_id_user' => 6, 'i_id_room' => 70, 'active_flag' => 1, 'i_left_by_deletion' => 1, 'dt_update' => '2024-07-29 09:10:37']);
        $db->insert('m_user_group', ['i_id_user' => 6, 'i_id_room' => 71, 'active_flag' => 1, 'dt_update' => '2024-04-01 10:00:00']);
    }

    /** @return array<int, int> 部屋ID => active_flag */
    private function roomFlags(int $userId): array
    {
        $rows = ConnectionManager::get('test')->execute('SELECT i_id_room, active_flag FROM m_user_group WHERE i_id_user = ? ORDER BY i_id_room', [$userId])->fetchAll('assoc');

        return array_combine(array_map('intval', array_column($rows, 'i_id_room')), array_map('intval', array_column($rows, 'active_flag')));
    }

    public function testRestore_bringsBackOnlyRoomsBeforeDeletion_withoutWarning(): void
    {
        $this->seedDeletedUserRooms();
        $this->login(1);
        $this->enableRetainFlashMessages();

        $this->post('/MUserInfo/restore/6');

        $this->assertFlashMessage('ユーザー「削除済みユーザー」を復元しました。', 'flash');
        $this->assertSame([70 => 0, 71 => 1], $this->roomFlags(6), '部屋異動で外れた部屋は戻らない');
        $this->assertCount(1, $this->_flashMessages['flash'] ?? [], '注意は出さない');
    }

    public function testRestore_withUnknownRooms_warnsToSetRooms(): void
    {
        $this->login(1);

        // 利用者 6 には、削除で外した所属が無い（時刻も合わない）
        $this->post('/MUserInfo/restore/6');

        $this->assertFlashMessageAt(0, 'ユーザー「削除済みユーザー」を復元しました。', 'flash');
        $this->assertFlashMessageAt(1, '削除前の所属部屋が分からないため、所属部屋なしで復元しました。必要に応じて編集画面で所属部屋を設定してください。', 'flash');
    }

    public function testIndex_deletedUser_showsOnlyRoomsBeforeDeletion(): void
    {
        $this->seedDeletedUserRooms();
        $this->login(1);

        $this->get('/MUserInfo?show_deleted=1');

        $this->assertResponseOk();
        $this->assertResponseContains('削除前の部屋');
        $this->assertResponseNotContains('異動で外れた部屋');
    }

    public function testEdit_deletedUser_canRenameButCannotChangeRooms(): void
    {
        $this->seedDeletedUserRooms();
        $this->login(1);

        $this->get('/MUserInfo/edit/6');
        $this->assertResponseContains('削除済みのユーザーは所属部屋を変更できません。');
        $this->assertResponseNotContains('name="rooms[70]"');

        $this->post('/MUserInfo/edit/6', ['c_login_account' => 'deleted_user', 'c_user_name' => '削除済みユーザー改', 'rooms' => [70 => '1', 71 => '1']]);

        $this->assertRedirect(['action' => 'index']);
        $this->assertSame('削除済みユーザー改', $this->userField(6, 'c_user_name'));
        $this->assertSame([70 => 1, 71 => 1], $this->roomFlags(6), '削除済みのまま所属中にならない');
    }

    // ------------------------------------------------------------ 登録・編集で選べる部屋（#821）

    /**
     * 部屋 80（管理者 1・システム管理者 4 が所属）、81（誰も所属しない）、82（削除済み、管理者 1 が所属）を作る。
     * 管理者 1 は部屋 1・80・82、システム管理者 4 は部屋 80 だけに所属する。
     */
    private function seedRoomsForChoices(): void
    {
        $db = ConnectionManager::get('test');
        foreach ([80 => ['自分の部屋B', 0], 81 => ['所属外の部屋', 0], 82 => ['削除済みの部屋', 1]] as $id => [$name, $del]) {
            $db->insert('m_room_info', ['i_id_room' => $id, 'c_room_name' => $name, 'i_disp_no' => $id, 'i_del_flg' => $del]);
        }
        foreach ([[1, 80], [1, 82], [4, 80]] as [$userId, $roomId]) {
            $db->insert('m_user_group', ['i_id_user' => $userId, 'i_id_room' => $roomId, 'active_flag' => 0]);
        }
    }

    /** @return list<int> 所属中の部屋（昇順） */
    private function activeRoomIds(int $userId): array
    {
        return array_keys(array_filter($this->roomFlags($userId), static fn(int $flag): bool => $flag === 0));
    }

    /**
     * @return array<string, array{int, list<int>, list<int>}> ログインする人, 出る部屋, 出ない部屋
     */
    public static function adminRoomChoices(): array
    {
        return [
            '管理者'         => [1, [1, 80], [81, 82]],
            'システム管理者' => [4, [80], [1, 81, 82]],
        ];
    }

    /**
     * 管理者・システム管理者でも、登録画面の部屋の選択肢は自分が所属している削除されていない部屋だけ。
     *
     * @dataProvider adminRoomChoices
     * @param list<int> $shown
     * @param list<int> $hidden
     */
    public function testAddForm_admin_showsOnlyOwnRooms(int $userId, array $shown, array $hidden): void
    {
        $this->seedRoomsForChoices();
        $this->login($userId);

        $this->get('/MUserInfo/add');

        $this->assertResponseOk();
        foreach ($shown as $roomId) {
            $this->assertResponseContains('name="MUserGroup[' . $roomId . '][i_id_room]"');
        }
        foreach ($hidden as $roomId) {
            $this->assertResponseNotContains('name="MUserGroup[' . $roomId . '][i_id_room]"');
        }
    }

    /**
     * 管理者・システム管理者でも、所属外の部屋・削除済みの部屋を POST しても登録しない。
     *
     * @return array<string, array{int, int}>
     */
    public static function adminRoomsOutsideOwn(): array
    {
        return [
            '管理者が所属外の部屋'           => [1, 81],
            '管理者が削除済みの部屋'         => [1, 82],
            'システム管理者が所属外の部屋'   => [4, 1],
        ];
    }

    /**
     * @dataProvider adminRoomsOutsideOwn
     */
    public function testAdd_admin_roomOutsideOwn_isRejected(int $userId, int $roomId): void
    {
        $this->seedRoomsForChoices();
        $this->login($userId);
        $this->enableRetainFlashMessages();
        $groupsBefore = $this->getTableLocator()->get('MUserGroup')->find()->count();

        $this->post('/MUserInfo/add', [
            'c_login_account' => 'outside_room_user', 'c_login_passwd' => 'password123', 'c_user_name' => '所属外テスト',
            'role' => 1, 'age' => 10, 'age_group' => 1, 'i_user_gender' => 1,
            'MUserGroup' => [['i_id_room' => $roomId]],
        ]);

        $this->assertResponseOk();
        $this->assertFlashMessage('自分が所属していない部屋には登録できません。', 'flash');
        $this->assertSame(0, $this->getTableLocator()->get('MUserInfo')->find()->where(['c_login_account' => 'outside_room_user'])->count());
        $this->assertSame($groupsBefore, $this->getTableLocator()->get('MUserGroup')->find()->count(), '所属も作らない');
    }

    /** システム管理者は自分が所属している部屋には登録できる */
    public function testAdd_systemAdmin_ownRoom_isRegistered(): void
    {
        $this->seedRoomsForChoices();
        $this->login(4);

        $this->post('/MUserInfo/add', [
            'c_login_account' => 'own_room_user', 'c_login_passwd' => 'password123', 'c_user_name' => '自室テスト',
            'role' => 1, 'age' => 10, 'age_group' => 1, 'i_user_gender' => 1,
            'MUserGroup' => [['i_id_room' => 80]],
        ]);

        $created = $this->getTableLocator()->get('MUserInfo')->find()->where(['c_login_account' => 'own_room_user'])->firstOrFail();
        $this->assertSame([80], $this->activeRoomIds((int)$created->i_id_user));
    }

    /** 管理者でも、編集画面の部屋の選択肢は自分が所属している削除されていない部屋だけ */
    public function testEditForm_admin_showsOnlyOwnRooms(): void
    {
        $this->seedRoomsForChoices();
        $this->login(1);

        $this->get('/MUserInfo/edit/3');

        $this->assertResponseOk();
        $this->assertResponseContains('name="rooms[1]"');
        $this->assertResponseContains('name="rooms[80]"');
        $this->assertResponseNotContains('name="rooms[81]"');
        $this->assertResponseNotContains('name="rooms[82]"');
    }

    /**
     * 管理者が編集で保存すると、選べる部屋だけチェックどおりになり、
     * 選べない部屋（所属外 81・削除済み 82）の既存の所属は残る。
     */
    public function testEdit_admin_keepsMembershipsOutsideOwnRooms(): void
    {
        $this->seedRoomsForChoices();
        $db = ConnectionManager::get('test');
        foreach ([81, 82] as $roomId) {
            $db->insert('m_user_group', ['i_id_user' => 3, 'i_id_room' => $roomId, 'active_flag' => 0]);
        }
        $this->login(1);

        // 部屋 1 を外し、80 を付ける。画面に出ない 81・82 は「0」を送っても外れない
        $this->post('/MUserInfo/edit/3', ['c_login_account' => 'non_staff_user', 'c_user_name' => '一般ユーザー', 'rooms' => [1 => '0', 80 => '1', 81 => '0', 82 => '0']]);

        $this->assertRedirect(['action' => 'index']);
        $this->assertSame([80, 81, 82], $this->activeRoomIds(3));
    }

    /** 管理者が所属外の部屋を POST しても、編集で付与されない */
    public function testEdit_admin_cannotAddRoomOutsideOwn(): void
    {
        $this->seedRoomsForChoices();
        $this->login(1);

        $this->post('/MUserInfo/edit/2', ['c_login_account' => 'staff_user', 'c_user_name' => '職員ユーザー', 'rooms' => [1 => '1', 81 => '1', 82 => '1']]);

        $this->assertRedirect(['action' => 'index']);
        $this->assertSame([1], $this->activeRoomIds(2));
        $this->assertSame(0, $this->getTableLocator()->get('MUserGroup')->find()->where(['i_id_user' => 2, 'i_id_room IN' => [81, 82]])->count());
    }

    public function testRestore_getMethod_isNotAllowed(): void
    {
        $this->login(1);
        $this->get('/MUserInfo/restore/6');
        $this->assertResponseCode(405);
    }

    // ----------------------------------------- 廃止した部屋の追加/削除（個別ルート）

    public function testRemovedRoomActionsAreNotExposed(): void
    {
        $this->login(1);

        $this->post('/m-user-info/add-room-to-user/3', ['i_id_room' => 1]);
        $this->assertResponseCode(404);

        $this->post('/m-user-info/remove-room-from-user/3/1');
        $this->assertResponseCode(404);
    }

    // ------------------------------------------------------ ログイン/ログアウト

    private function setPassword(int $userId, string $plain): void
    {
        ConnectionManager::get('test')->update(
            'm_user_info',
            ['c_login_passwd' => (new DefaultPasswordHasher())->hash($plain)],
            ['i_id_user' => $userId]
        );
    }

    public function testLogin_getShowsForm(): void
    {
        $this->get('/MUserInfo/login');
        $this->assertResponseOk();
    }

    public function testLogin_wrongPassword_showsErrorAndRecordsFailure(): void
    {
        $this->setPassword(2, 'correct-pass');
        $this->enableCsrfToken();
        $before = $this->auditCount('user_login_failed');

        $this->post('/MUserInfo/login', ['c_login_account' => 'staff_user', 'c_login_passwd' => 'wrong']);

        $this->assertResponseOk();
        $this->assertResponseContains('ユーザー名またはパスワードが正しくありません。');
        $this->assertSame($before + 1, $this->auditCount('user_login_failed'));
    }

    public function testLogin_unknownAccount_recordsFailureAsUnknownAccount(): void
    {
        $this->enableCsrfToken();
        $before = $this->auditCount('user_login_failed');

        $this->post('/MUserInfo/login', ['c_login_account' => 'nobody', 'c_login_passwd' => 'x']);

        $this->assertResponseOk();
        $this->assertSame($before + 1, $this->auditCount('user_login_failed'));
    }

    /**
     * @return array<string, array{int, int, string}> [ユーザーID, i_admin, 遷移先]
     */
    public static function loginDestinations(): array
    {
        return [
            '管理者は予約画面' => [1, 1, '/TReservationInfo'],
            '児童は予約画面' => [3, 0, '/TReservationInfo'],
            '職員はホーム' => [2, 0, '/pages/home'],
        ];
    }

    /**
     * @dataProvider loginDestinations
     */
    public function testLogin_success_redirectsByRole(int $userId, int $iAdmin, string $destination): void
    {
        $this->setPassword($userId, 'correct-pass');
        ConnectionManager::get('test')->update('m_user_info', ['i_admin' => $iAdmin], ['i_id_user' => $userId]);
        $this->enableCsrfToken();
        $before = $this->auditCount('user_login');

        $this->post('/MUserInfo/login', ['c_login_account' => self::ACCOUNTS[$userId], 'c_login_passwd' => 'correct-pass']);

        $this->assertRedirectContains($destination);
        $this->assertSame($before + 1, $this->auditCount('user_login'));
    }

    public function testLogin_followsSafeRedirectOnly(): void
    {
        $this->setPassword(1, 'correct-pass');
        $this->enableCsrfToken();

        $this->post('/MUserInfo/login?redirect=/Approval/adminIndex', ['c_login_account' => 'admin_user', 'c_login_passwd' => 'correct-pass']);
        $this->assertRedirectContains('/Approval/adminIndex');

        $this->post('/MUserInfo/login?redirect=https://evil.example/', ['c_login_account' => 'admin_user', 'c_login_passwd' => 'correct-pass']);
        $this->assertRedirectContains('/TReservationInfo');
        $this->assertRedirectNotContains('evil.example');
    }

    public function testLogin_disabledAccount_isLoggedOutWithMessage(): void
    {
        $this->setPassword(2, 'correct-pass');
        ConnectionManager::get('test')->update('m_user_info', ['i_enable' => 1], ['i_id_user' => 2]);
        $this->enableCsrfToken();
        $before = $this->auditCount('user_login');

        $this->post('/MUserInfo/login', ['c_login_account' => 'staff_user', 'c_login_passwd' => 'correct-pass']);

        $this->assertRedirectContains('/MUserInfo/login');
        $this->assertFlashMessage('このアカウントは無効化されています。', 'flash');
        $this->assertSame($before, $this->auditCount('user_login'), '無効なアカウントのログインは記録されない');
    }

    public function testLogout_clearsSessionAndRecordsAudit(): void
    {
        $this->login(2, 0);
        $before = $this->auditCount('user_logout');

        $this->get('/MUserInfo/logout');

        $this->assertRedirectContains('/MUserInfo/login');
        $this->assertSame($before + 1, $this->auditCount('user_logout'));
        $this->assertSession(null, 'Auth');
    }

    // ------------------------------------------------ 管理者によるパスワード変更

    public function testAdminChangePassword_nonAdminIsRejected(): void
    {
        $this->login(2, 0);

        $this->get('/MUserInfo/admin_change_password');

        $this->assertRedirect(['controller' => 'MUserInfo', 'action' => 'index']); // UserPasswordController に分けたため、行き先をコントローラー名付きで書く（#989）
        $this->assertFlashMessage('この機能は管理者のみ利用できます。', 'flash');
    }

    public function testAdminChangePassword_rejectsInvalidInput(): void
    {
        $this->login(1);

        $this->post('/MUserInfo/admin_change_password', ['user_id' => 999, 'new_password' => 'abcd', 'confirm_password' => 'abcd']);
        $this->assertRedirect(['action' => 'adminChangePassword']);
        $this->assertFlashMessage('ユーザーを選択してください。', 'flash');

        $this->post('/MUserInfo/admin_change_password', ['user_id' => 2, 'new_password' => 'abcd', 'confirm_password' => 'zzzz']);
        $this->assertFlashMessage('新しいパスワードが一致しません。', 'flash');

        $this->post('/MUserInfo/admin_change_password', ['user_id' => 2, 'new_password' => 'a', 'confirm_password' => 'a']);
        $this->assertFlashMessage('新しいパスワードは2文字以上にしてください。', 'flash');
    }

    public function testAdminChangePassword_success_changesPasswordAndRecordsAudit(): void
    {
        $this->login(1);
        $before = $this->auditCount('user_password_change_admin');

        $this->post('/MUserInfo/admin_change_password', ['user_id' => 2, 'new_password' => 'new-pass-1', 'confirm_password' => 'new-pass-1']);

        $this->assertRedirect(['controller' => 'MUserInfo', 'action' => 'index']); // 同上（#989）
        $this->assertFlashMessage('パスワードを変更しました。', 'flash');
        $hash = (string)$this->getTableLocator()->get('MUserInfo')->get(2)->c_login_passwd;
        $this->assertTrue((new DefaultPasswordHasher())->check('new-pass-1', $hash));
        $this->assertSame($before + 1, $this->auditCount('user_password_change_admin'));
    }

    // ------------------------------------------------ 本人によるパスワード変更

    public function testGeneralPasswordReset_showsForm(): void
    {
        $this->login(2, 0);
        $this->get('/MUserInfo/generalPasswordReset');
        $this->assertResponseOk();
        $this->assertSame(2, (int)$this->viewVariable('user')->i_id_user);
    }

    public function testGeneralPasswordReset_rejectsMismatchAndTooShort(): void
    {
        $this->login(2, 0);

        $this->post('/MUserInfo/generalPasswordReset', ['new_password' => 'abcd', 'confirm_password' => 'zzzz']);
        $this->assertRedirectContains('/MUserInfo/generalPasswordReset');
        $this->assertFlashMessage('新しいパスワードが一致しません。', 'flash');

        $this->post('/MUserInfo/generalPasswordReset', ['new_password' => 'a', 'confirm_password' => 'a']);
        $this->assertFlashMessage('新しいパスワードは2文字以上にしてください。', 'flash');
        $this->assertSame('dummy_password', (string)$this->userField(2, 'c_login_passwd'));
    }

    public function testGeneralPasswordReset_success_changesOwnPassword(): void
    {
        $this->login(2, 0);
        $before = $this->auditCount('user_password_change_self');

        $this->post('/MUserInfo/generalPasswordReset', ['new_password' => 'my-new-pass', 'confirm_password' => 'my-new-pass']);

        $this->assertRedirectContains('/TReservationInfo');
        $this->assertFlashMessage('パスワードを変更しました。', 'flash');
        $this->assertTrue((new DefaultPasswordHasher())->check('my-new-pass', (string)$this->userField(2, 'c_login_passwd')));
        $this->assertSame($before + 1, $this->auditCount('user_password_change_self'));
    }

    // ------------------------------------------------------ 所属部屋API

    public function testAddUserRooms_nonAdminIsRejected(): void
    {
        $this->login(2, 0);

        $this->post('/MUserInfo/add-user-rooms', ['i_id_user' => 3, 'room_names' => ['Lorem ipsum dolor sit amet']]);

        $this->assertFalse($this->json()['ok']);
        $this->assertSame('権限がありません', $this->json()['message']);
    }

    public function testAddUserRooms_adminReassignsRooms(): void
    {
        $this->login(1);

        $this->post('/MUserInfo/add-user-rooms', ['i_id_user' => 3, 'room_names' => ['Lorem ipsum dolor sit amet', '存在しない部屋']]);

        $json = $this->json();
        $this->assertTrue($json['ok']);
        $this->assertSame(1, $json['created']);
        $this->assertNotEmpty($json['errors'], '存在しない部屋名はエラーとして返る');
    }

    /**
     * @return array<string, array{string, array<string, mixed>, int}>
     */
    public static function invalidPermissionInputs(): array
    {
        return [
            '管理者権限: 必須項目なし' => ['/MUserInfo/updateAdminStatus', [], 400],
            '管理者権限: 対象なし' => ['/MUserInfo/updateAdminStatus', ['i_id_user' => 9999, 'i_admin' => 1], 404],
            'システム管理者: 必須項目なし' => ['/MUserInfo/updateSystemAdminStatus', [], 400],
            '帳票権限: 必須項目なし' => ['/MUserInfo/updateReportAccess', [], 400],
            '職員区分: 必須項目なし' => ['/MUserInfo/updateUserLevel', [], 400],
            '所属部屋: 必須項目なし(JSON APIはok=falseを200で返す)' => ['/MUserInfo/addUserRooms', [], 200],
        ];
    }

    /**
     * @dataProvider invalidPermissionInputs
     * @param array<string, mixed> $data
     */
    public function testInvalidInputReturns4xxNotServerError(string $url, array $data, int $status): void
    {
        $this->login(4);
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);

        $this->post($url, $data);

        $this->assertResponseCode($status);
    }
}
