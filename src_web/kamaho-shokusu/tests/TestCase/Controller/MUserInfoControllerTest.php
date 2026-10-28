<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Controller\MUserInfoController;
use Cake\Core\Configure;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * App\Controller\MUserInfoController Test Case
 *
 * @uses \App\Controller\MUserInfoController
 */
class MUserInfoControllerTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * Fixtures
     *
     * @var list<string>
     */
    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
    ];

    public function setUp(): void
    {
        parent::setUp();
        Configure::write('debug', true);
    }

    public function tearDown(): void
    {
        Configure::write('debug', false);
        parent::tearDown();
    }

    /**
     * Test index method
     *
     * @return void
     * @uses \App\Controller\MUserInfoController::index()
     */
    public function testIndex(): void
    {
        $this->setAuthenticatedSession();
        $this->get('/MUserInfo');
        $this->assertResponseOk();
        $this->assertResponseContains('ログインID');
        $this->assertResponseContains('staff_user');
        $this->assertResponseNotContains('表示順');
    }

    /**
     * 一覧の所属表示でも、退出済みの部屋は所属として表示しない（詳細画面と揃える）。
     */
    public function testIndexHidesLeftRooms(): void
    {
        $rooms = $this->getTableLocator()->get('MRoomInfo');
        $base = $rooms->get(1)->toArray();
        $rooms->updateAll(['c_room_name' => '現在のルーム'], ['i_id_room' => 1]);
        $rooms->getConnection()->insert('m_room_info', ['i_id_room' => 2, 'c_room_name' => '退出したルーム'] + $base);

        $groups = $this->getTableLocator()->get('MUserGroup');
        $groupBase = $groups->find()->where(['i_id_user' => 1])->firstOrFail()->toArray();
        unset($groupBase['i_id_group'], $groupBase['id']);
        $groups->getConnection()->insert('m_user_group', ['i_id_room' => 2, 'active_flag' => 1] + $groupBase);

        $this->setAuthenticatedSession();
        $this->get('/MUserInfo');
        $this->assertResponseOk();
        $this->assertResponseContains('現在のルーム');
        $this->assertResponseNotContains('退出したルーム');
        // 退出済みを数えると全部屋所属と誤判定されるため、その表示にならないこと
        $this->assertResponseNotContains('全部屋所属');
    }

    /**
     * 現在の所属が無く、退出済みの部屋だけがあるユーザーは「未所属」になる。
     */
    public function testIndexShowsUnaffiliatedWhenAllRoomsLeft(): void
    {
        $this->getTableLocator()->get('MUserGroup')->updateAll(['active_flag' => 1], ['i_id_user' => 1]);
        $this->getTableLocator()->get('MRoomInfo')->updateAll(['c_room_name' => '退出したルーム'], ['i_id_room' => 1]);
        $this->getTableLocator()->get('MUserInfo')->updateAll(['i_admin' => 1], ['i_id_user' => 1]);

        $this->setAuthenticatedSession();
        $this->get('/MUserInfo?limit=200');
        $this->assertResponseOk();
        $this->assertResponseContains('未所属');
    }

    /**
     * 削除済みユーザーの所属を作る。部屋1(現役)、部屋2(別の現役)、部屋3(削除済み)。
     * ユーザー6は部屋1・部屋3に所属していたが、削除時に全て退出済みになっている。
     */
    private function prepareDeletedUserRooms(): void
    {
        $rooms = $this->getTableLocator()->get('MRoomInfo');
        $base = $rooms->get(1)->toArray();
        $rooms->updateAll(['c_room_name' => '削除前のルーム'], ['i_id_room' => 1]);
        // 部屋が1つだけだと「全部屋所属」の表示になるため、有効な部屋をもう1つ作る
        $rooms->getConnection()->insert('m_room_info', ['i_id_room' => 2, 'c_room_name' => '別のルーム'] + $base);
        $rooms->getConnection()->insert('m_room_info', ['i_id_room' => 3, 'c_room_name' => '削除済みのルーム', 'i_del_flg' => 1] + $base);

        $groups = $this->getTableLocator()->get('MUserGroup');
        $groupBase = $groups->find()->where(['i_id_user' => 6])->firstOrFail()->toArray();
        unset($groupBase['i_id_group'], $groupBase['id']);
        $groups->updateAll(['active_flag' => 1], ['i_id_user' => 6]);
        $groups->getConnection()->insert('m_user_group', ['i_id_room' => 3, 'active_flag' => 1] + $groupBase);
        $this->getTableLocator()->get('MUserInfo')->updateAll(['i_del_flag' => 1], ['i_id_user' => 6]);
    }

    /**
     * 削除済みユーザーの詳細では、削除前の所属部屋を表示する。削除済みの部屋は表示しない。
     */
    public function testDeletedUserViewShowsRoomsBeforeDeletion(): void
    {
        $this->prepareDeletedUserRooms();
        $this->setAuthenticatedSession();
        $this->get('/MUserInfo/view/6');
        $this->assertResponseOk();
        $this->assertResponseContains('削除前のルーム');
        $this->assertResponseNotContains('削除済みのルーム');
    }

    /**
     * 削除済みユーザーの一覧でも、削除前の所属部屋を表示する。削除済みの部屋は表示しない。
     */
    public function testDeletedUserIndexShowsRoomsBeforeDeletion(): void
    {
        $this->prepareDeletedUserRooms();
        $this->setAuthenticatedSession();
        $this->get('/MUserInfo?show_deleted=1');
        $this->assertResponseOk();
        $this->assertResponseContains('削除前のルーム');
        $this->assertResponseNotContains('削除済みのルーム');
    }

    /**
     * Test view method
     *
     * @return void
     * @uses \App\Controller\MUserInfoController::view()
     */
    public function testView(): void
    {
        $this->setAuthenticatedSession();
        $this->get('/MUserInfo/view/1');
        $this->assertResponseOk();
    }

    /**
     * 詳細画面に所属部屋が表示される。退出済みの部屋は表示しない。
     */
    public function testViewShowsActiveRoomsOnly(): void
    {
        $rooms = $this->getTableLocator()->get('MRoomInfo');
        $rooms->updateAll(['c_room_name' => '現在の部屋'], ['i_id_room' => 1]);
        $groups = $this->getTableLocator()->get('MUserGroup');
        $groups->updateAll(['active_flag' => 1], ['i_id_user' => 2, 'i_id_room' => 1]);
        $groups->updateAll(['active_flag' => 0], ['i_id_user' => 1, 'i_id_room' => 1]);

        $this->setAuthenticatedSession();
        $this->get('/MUserInfo/view/1');
        $this->assertResponseOk();
        $this->assertResponseContains('所属部屋');
        $this->assertResponseContains('現在の部屋');

        $this->get('/MUserInfo/view/2');
        $this->assertResponseOk();
        $this->assertResponseContains('所属なし');
        $this->assertResponseNotContains('現在の部屋');
    }

    /**
     * 所属中・退出済み・削除済みの部屋が混在するとき、所属中の部屋だけが表示される。
     */
    public function testViewHidesLeftAndDeletedRooms(): void
    {
        $rooms = $this->getTableLocator()->get('MRoomInfo');
        $base = $rooms->get(1)->toArray();
        $addRoom = function (int $id, string $name, int $delFlg) use ($rooms, $base): void {
            $row = ['i_id_room' => $id, 'c_room_name' => $name, 'i_del_flg' => $delFlg] + $base;
            $rooms->getConnection()->insert('m_room_info', $row);
        };
        $rooms->updateAll(['c_room_name' => '現在の部屋'], ['i_id_room' => 1]);
        $addRoom(2, '退出した部屋', 0);
        $addRoom(3, '削除された部屋', 1);

        $groups = $this->getTableLocator()->get('MUserGroup');
        $groupBase = $groups->find()->where(['i_id_user' => 1])->firstOrFail()->toArray();
        unset($groupBase['i_id_group'], $groupBase['id']);
        foreach ([[2, 1], [3, 0]] as [$roomId, $activeFlag]) {
            $groups->getConnection()->insert('m_user_group', ['i_id_room' => $roomId, 'active_flag' => $activeFlag] + $groupBase);
        }

        $this->setAuthenticatedSession();
        $this->get('/MUserInfo/view/1');
        $this->assertResponseOk();
        $this->assertResponseContains('現在の部屋');
        $this->assertResponseNotContains('退出した部屋');
        $this->assertResponseNotContains('削除された部屋');
    }

    /**
     * 編集画面の操作メニューに、ユーザー詳細へのリンクがある。
     */
    public function testEditHasLinkToView(): void
    {
        $this->setAuthenticatedSession();
        $this->get('/MUserInfo/edit/1');
        $this->assertResponseOk();
        $this->assertResponseContains('ユーザー詳細を表示する');
        $this->assertResponseContains('href="/MUserInfo/view/1"');
    }

    /**
     * 一般ユーザーも、自分の編集画面から自分の詳細へ移動できる。
     */
    public function testEditHasLinkToViewForOwner(): void
    {
        $this->setAuthenticatedSession(false);
        $this->get('/MUserInfo/edit/2');
        $this->assertResponseOk();
        $this->assertResponseContains('href="/MUserInfo/view/2"');
    }

    /**
     * Test add method
     *
     * @return void
     * @uses \App\Controller\MUserInfoController::add()
     */
    public function testAdd(): void
    {
        $this->setAuthenticatedSession();
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->post('/MUserInfo/add', [
            'c_login_account' => 'test_login_2',
            'c_login_passwd' => 'password123',
            'c_user_name' => '追加テストユーザー',
            'role' => 0,
            'staff_id' => '900002',
            'age' => 10,
            'age_group' => 1,
            'i_user_gender' => 1,
            'MUserGroup' => [
                ['i_id_room' => 1],
            ],
        ]);
        $this->assertResponseSuccess();
        $this->assertRedirect(['action' => 'index']);
    }

    /**
     * Test edit method
     *
     * @return void
     * @uses \App\Controller\MUserInfoController::edit()
     */
    public function testEdit(): void
    {
        $this->setAuthenticatedSession();
        $this->get('/MUserInfo/edit/1');
        $this->assertResponseOk();
    }

    /**
     * 管理者が編集画面を開くと、対象ユーザーIDを引き継いだパスワード変更リンクが表示される。
     *
     * @return void
     * @uses \App\Controller\MUserInfoController::edit()
     */
    public function testEditShowsPasswordChangeLinkForAdmin(): void
    {
        $this->setAuthenticatedSession(true);
        $this->get('/MUserInfo/edit/2');
        $this->assertResponseOk();
        $this->assertResponseContains('パスワード変更');
        $this->assertResponseContains('admin_change_password?user_id=2');
        // リンクではなくボタンの見た目で表示する
        $this->assertResponseRegExp('/<a [^>]*class="btn btn-outline-primary btn-sm"[^>]*>🔑 パスワード変更<\/a>/u');
    }

    /**
     * 削除済みユーザーは変更画面の対象に入らない（押しても未選択になる）ため、
     * 編集画面にパスワード変更ボタンを出さない。
     *
     * @return void
     * @uses \App\Controller\MUserInfoController::edit()
     */
    public function testPasswordChangeButtonHiddenForDeletedUser(): void
    {
        $this->setAuthenticatedSession(true);

        $this->get('/MUserInfo/edit/6');
        $this->assertResponseContains('deleted_user');
        $this->assertResponseOk();
        $this->assertResponseNotContains('admin_change_password?user_id=6');
    }

    /**
     * 管理者でないユーザーが自分の編集画面を開いても、パスワード変更リンクは表示されない。
     *
     * @return void
     * @uses \App\Controller\MUserInfoController::edit()
     */
    public function testEditHidesPasswordChangeLinkForNonAdmin(): void
    {
        $this->setAuthenticatedSession(false);
        $this->get('/MUserInfo/edit/2');
        $this->assertResponseOk();
        // レイアウト共通ナビの「パスワード変更」(自分用) とは別物なので、
        // 編集画面固有のリンク先 (admin_change_password?user_id=) の有無で判定する。
        $this->assertResponseNotContains('admin_change_password?user_id=2');
    }

    private function postEdit(int $id, array $data): void
    {
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->post('/MUserInfo/edit/' . $id, $data);
    }

    private function userRow(int $id): \App\Model\Entity\MUserInfo
    {
        return $this->getTableLocator()->get('MUserInfo')->get($id);
    }

    /**
     * 管理者は、他のユーザーの役職を変更できる。職員以外にすると職員IDは持たない（新規登録と同じ）。
     */
    public function testAdminCanChangeRoleOfOtherUser(): void
    {
        $this->getTableLocator()->get('MUserInfo')->updateAll(['i_id_staff' => 777], ['i_id_user' => 2]);
        $this->setAuthenticatedSession();
        $this->postEdit(2, ['c_login_account' => 'staff_user', 'c_user_name' => '職員', 'i_user_level' => '3', 'rooms' => [1 => '1']]);

        $this->assertRedirect(['action' => 'index']);
        $row = $this->userRow(2);
        $this->assertSame(3, (int)$row->i_user_level);
        $this->assertNull($row->i_id_staff);
    }

    /**
     * 役職を職員にするときは職員IDが必須。無ければ変更しない。
     */
    public function testChangingRoleToStaffRequiresStaffId(): void
    {
        $this->setAuthenticatedSession();
        $this->postEdit(3, ['c_login_account' => 'non_staff_user', 'c_user_name' => '児童', 'i_user_level' => '0', 'i_id_staff' => '', 'rooms' => [1 => '1']]);

        $this->assertResponseOk();
        $this->assertResponseContains('職員IDを入力してください');
        $this->assertSame(1, (int)$this->userRow(3)->i_user_level);

        $this->postEdit(3, ['c_login_account' => 'non_staff_user', 'c_user_name' => '児童', 'i_user_level' => '0', 'i_id_staff' => '4321', 'rooms' => [1 => '1']]);
        $this->assertRedirect(['action' => 'index']);
        $row = $this->userRow(3);
        $this->assertSame(0, (int)$row->i_user_level);
        $this->assertSame(4321, (int)$row->i_id_staff);
    }

    /**
     * 役職として選べない値（管理者を表す 2 など）は保存しない。
     */
    public function testInvalidRoleValueIsRejected(): void
    {
        $this->setAuthenticatedSession();
        $this->postEdit(3, ['c_login_account' => 'non_staff_user', 'c_user_name' => '児童', 'i_user_level' => '2', 'rooms' => [1 => '1']]);

        $this->assertResponseOk();
        $this->assertSame(1, (int)$this->userRow(3)->i_user_level);
    }

    /**
     * 自分自身の役職は、管理者でも変更できない（欄が出ず、送っても反映されない）。
     */
    public function testAdminCannotChangeOwnRole(): void
    {
        $this->setAuthenticatedSession();
        $this->get('/MUserInfo/edit/1');
        $this->assertResponseNotContains('id="userLevelSelect"');

        $this->postEdit(1, ['c_login_account' => 'admin_user', 'c_user_name' => '管理者', 'i_user_level' => '3', 'rooms' => [1 => '1']]);
        $this->assertSame(0, (int)$this->userRow(1)->i_user_level);
    }

    /**
     * 管理者でないユーザーは、自分の編集で役職・権限を書き換えられない。
     */
    public function testNonAdminCannotEscalateOwnRoleOrAdmin(): void
    {
        $this->setAuthenticatedSession(false);
        $this->postEdit(2, [
            'c_login_account' => 'staff_user', 'c_user_name' => '職員', 'rooms' => [1 => '1'],
            'i_user_level' => '3', 'i_admin' => '3', 'i_enable' => '1', 'i_del_flag' => '1',
        ]);

        $row = $this->userRow(2);
        $this->assertSame(0, (int)$row->i_user_level);
        $this->assertSame(0, (int)$row->i_admin);
        $this->assertSame(0, (int)$row->i_enable);
        $this->assertSame(0, (int)$row->i_del_flag);
    }

    /**
     * 管理者が他のユーザーを編集するときは、役職の欄が表示される。
     */
    public function testEditShowsRoleFieldForAdminOnOtherUser(): void
    {
        $this->setAuthenticatedSession();
        $this->get('/MUserInfo/edit/2');
        $this->assertResponseOk();
        $this->assertResponseContains('id="userLevelSelect"');
        $this->assertResponseContains('その他');

        $this->setAuthenticatedSession(false);
        $this->get('/MUserInfo/edit/2');
        $this->assertResponseNotContains('id="userLevelSelect"');
    }

    /**
     * 職員が同じ部屋の児童を編集できる。役職欄・所属部屋の欄は出ず、保存しても所属は変わらない。
     */
    public function testStaffCanEditChildInSameRoomWithoutRoleOrRooms(): void
    {
        $this->setAuthenticatedSession(false);
        $this->get('/MUserInfo/edit/3');
        $this->assertResponseOk();
        $this->assertResponseNotContains('id="userLevelSelect"');
        $this->assertResponseNotContains('所属部屋');

        $this->postEdit(3, ['c_login_account' => 'non_staff_user', 'c_user_name' => '名前変更', 'i_user_level' => '0', 'rooms' => []]);
        $this->assertRedirect(['action' => 'index']);
        $row = $this->userRow(3);
        $this->assertSame('名前変更', $row->c_user_name);
        $this->assertSame(1, (int)$row->i_user_level);
        $active = $this->getTableLocator()->get('MUserGroup')->find()->where(['i_id_user' => 3, 'active_flag' => 0])->count();
        $this->assertSame(1, $active);
    }

    /**
     * 職員IDが空の既存の職員は、管理者が役職を変えずに編集（氏名の変更）できる。
     */
    public function testAdminCanEditStaffWithoutStaffIdWhenRoleUnchanged(): void
    {
        $this->getTableLocator()->get('MUserInfo')->updateAll(['i_id_staff' => null], ['i_id_user' => 2]);
        $this->setAuthenticatedSession();
        $this->postEdit(2, ['c_login_account' => 'staff_user', 'c_user_name' => '職員ID無しの職員', 'i_user_level' => '0', 'i_id_staff' => '', 'rooms' => [1 => '1']]);

        $this->assertRedirect(['action' => 'index']);
        $this->assertSame('職員ID無しの職員', $this->userRow(2)->c_user_name);
    }

    /**
     * 職員が児童を編集するとき、ログインIDは変更できない（認証情報のため）。氏名は変更できる。
     */
    public function testStaffCannotChangeChildLoginAccount(): void
    {
        $this->setAuthenticatedSession(false);
        $this->postEdit(3, ['c_login_account' => 'hijacked_login', 'c_user_name' => '氏名だけ変更', 'rooms' => []]);

        $this->assertRedirect(['action' => 'index']);
        $row = $this->userRow(3);
        $this->assertSame('non_staff_user', $row->c_login_account);
        $this->assertSame('氏名だけ変更', $row->c_user_name);
    }

    /**
     * 職員が児童を編集するとき、i_admin などを送っても反映されない。
     */
    public function testStaffCannotEscalateChildViaEdit(): void
    {
        $this->setAuthenticatedSession(false);
        $this->postEdit(3, [
            'c_login_account' => 'non_staff_user', 'c_user_name' => '児童', 'rooms' => [],
            'i_admin' => '3', 'i_user_level' => '0', 'i_enable' => '1', 'i_del_flag' => '1',
        ]);

        $row = $this->userRow(3);
        $this->assertSame(0, (int)($row->i_admin ?? 0));
        $this->assertSame(1, (int)$row->i_user_level);
        $this->assertSame(0, (int)$row->i_enable);
        $this->assertSame(0, (int)$row->i_del_flag);
    }

    /**
     * 同じ部屋でも、退出済みの児童は職員が編集できない。
     */
    public function testStaffCannotEditChildWhoLeftTheRoom(): void
    {
        $this->getTableLocator()->get('MUserGroup')->updateAll(['active_flag' => 1], ['i_id_user' => 3]);
        $this->setAuthenticatedSession(false);
        $this->get('/MUserInfo/edit/3');
        $this->assertRedirect(['action' => 'index']);
    }

    /**
     * ブロック長も、同じ部屋の児童を編集できる。
     */
    public function testBlockLeaderCanEditChildInSameRoom(): void
    {
        $this->getTableLocator()->get('MUserInfo')->updateAll(['i_admin' => 2, 'i_user_level' => 1], ['i_id_user' => 2]);
        $this->setAuthenticatedSession(false);
        $this->get('/MUserInfo/edit/3');
        $this->assertResponseOk();
        $this->assertResponseNotContains('id="userLevelSelect"');
    }

    /**
     * 職員でも、児童でないユーザー（他の職員）は編集できない。
     */
    public function testStaffCannotEditNonChildUser(): void
    {
        $this->setAuthenticatedSession(false);
        $this->get('/MUserInfo/edit/5');
        $this->assertRedirect(['action' => 'index']);
    }

    /**
     * Test delete method
     *
     * @return void
     * @uses \App\Controller\MUserInfoController::delete()
     */
    public function testDelete(): void
    {
        $this->setAuthenticatedSession();
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->post('/MUserInfo/delete/1');
        $this->assertResponseSuccess();
        $this->assertRedirect(['action' => 'index']);
    }

    public function testRestoreAsAdmin(): void
    {
        $this->setAuthenticatedSession(true);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->post('/MUserInfo/restore/6');
        $this->assertResponseSuccess();
        $this->assertRedirect(['action' => 'index']);

        $user = $this->getTableLocator()->get('MUserInfo')->get(6);
        $this->assertSame(0, (int)$user->i_del_flag);
    }

    public function testRestoreAsNonAdminDenied(): void
    {
        $this->setAuthenticatedSession(false);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->post('/MUserInfo/restore/6');
        $this->assertResponseSuccess();
        $this->assertRedirect(['action' => 'index']);

        $user = $this->getTableLocator()->get('MUserInfo')->get(6);
        $this->assertSame(1, (int)$user->i_del_flag);
    }

    /**
     * 一般ADMIN（SYSTEM_ADMINではない）が updateAdminStatus に i_admin=3(SYSTEM_ADMIN) を
     * 送っても拒否され、対象ユーザーの i_admin が 3 にならないことを検証する。
     *
     * @return void
     * @uses \App\Controller\MUserInfoController::updateAdminStatus()
     */
    public function testUpdateAdminStatusAsGeneralAdminCannotEscalateToSystemAdmin(): void
    {
        $this->setAuthenticatedSession(true);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->post('/MUserInfo/update-admin-status', [
            'i_id_user' => 2,
            'i_admin' => 3,
        ]);

        // 昇格の拒否はサーバーエラー(500)ではなく 403 で返す
        $this->assertResponseCode(403);

        $user = $this->getTableLocator()->get('MUserInfo')->get(2);
        $this->assertNotSame(3, (int)$user->i_admin);
    }

    /**
     * 一般ADMIN（SYSTEM_ADMINではない）が updateUserLevel に i_admin=3(SYSTEM_ADMIN) を
     * 送っても拒否され、対象ユーザーの i_admin が 3 にならないことを検証する。
     *
     * @return void
     * @uses \App\Controller\MUserInfoController::updateUserLevel()
     */
    public function testUpdateUserLevelAsGeneralAdminCannotEscalateToSystemAdmin(): void
    {
        $this->setAuthenticatedSession(true);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->post('/MUserInfo/update-user-level', [
            'i_id_user' => 2,
            'i_admin' => 3,
        ]);

        // 昇格の拒否はサーバーエラー(500)ではなく 403 で返す
        $this->assertResponseCode(403);

        $user = $this->getTableLocator()->get('MUserInfo')->get(2);
        $this->assertNotSame(3, (int)$user->i_admin);
    }

    /**
     * システム管理者は updateSystemAdminStatus 経由で SYSTEM_ADMIN へ昇格させられることを検証する。
     *
     * @return void
     * @uses \App\Controller\MUserInfoController::updateSystemAdminStatus()
     */
    public function testUpdateSystemAdminStatusAsSystemAdminCanEscalate(): void
    {
        $this->session([
            'Auth' => [
                'i_id_user'       => 4,
                'c_login_account' => 'system_admin_user',
                'c_user_name'     => 'システム管理者',
                'i_admin'         => 3,
                'i_user_level'    => 0,
                'i_id_room'       => 1,
            ],
        ]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->post('/MUserInfo/update-system-admin-status', [
            'i_id_user' => 2,
            'i_system_admin' => 1,
        ]);

        $this->assertResponseOk();

        $user = $this->getTableLocator()->get('MUserInfo')->get(2);
        $this->assertSame(3, (int)$user->i_admin);
    }

    /**
     * 編集画面のパスワード変更リンク経由（?user_id=）で遷移すると、対象ユーザーが事前選択される。
     *
     * @return void
     * @uses \App\Controller\MUserInfoController::adminChangePassword()
     */
    public function testAdminChangePasswordPreselectsUserFromQuery(): void
    {
        $this->setAuthenticatedSession(true);
        $this->get('/MUserInfo/admin_change_password?user_id=2');
        $this->assertResponseOk();

        $selectedUser = $this->viewVariable('selectedUser');
        $this->assertNotNull($selectedUser);
        $this->assertSame(2, (int)$selectedUser->i_id_user);
    }

    /**
     * 存在しないユーザーIDが ?user_id= に渡されても、エラーにならず未選択にフォールバックする。
     *
     * @return void
     * @uses \App\Controller\MUserInfoController::adminChangePassword()
     */
    public function testAdminChangePasswordIgnoresNonexistentQueryUserId(): void
    {
        $this->setAuthenticatedSession(true);
        $this->get('/MUserInfo/admin_change_password?user_id=99999');
        $this->assertResponseOk();
        $this->assertNull($this->viewVariable('selectedUser'));
    }

    /**
     * 削除済みユーザーのIDが ?user_id= に渡されても、エラーにならず未選択にフォールバックする。
     *
     * @return void
     * @uses \App\Controller\MUserInfoController::adminChangePassword()
     */
    public function testAdminChangePasswordIgnoresDeletedQueryUserId(): void
    {
        $this->setAuthenticatedSession(true);
        // fixture の i_id_user=6 (deleted_user) は i_del_flag=1（削除済み）
        $this->get('/MUserInfo/admin_change_password?user_id=6');
        $this->assertResponseOk();
        $this->assertNull($this->viewVariable('selectedUser'));
    }

    /**
     * 別の権限（ブロック長）を持つユーザーへ、確認(overwrite)なしで管理者を付与すると拒否される。
     * 画面の確認を経ない上書きで、ブロック長が黙って管理者に置き換わるのを防ぐ。
     */
    public function testRoleChangeWithoutOverwriteConfirmationIsRejected(): void
    {
        $table = $this->getTableLocator()->get('MUserInfo');
        $table->updateAll(['i_admin' => 2], ['i_id_user' => 3]);

        $this->setAuthenticatedSession(true);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->post('/MUserInfo/update-admin-status', ['i_id_user' => 3, 'i_admin' => 1]);

        $this->assertResponseCode(409);
        $this->assertSame(2, (int)$table->get(3)->i_admin);
    }

    /**
     * 確認済み(overwrite=1)なら、別の権限から変更できる（排他のため置き換わる）。
     */
    public function testRoleChangeWithOverwriteConfirmationSucceeds(): void
    {
        $table = $this->getTableLocator()->get('MUserInfo');
        $table->updateAll(['i_admin' => 2], ['i_id_user' => 3]);

        $this->setAuthenticatedSession(true);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->post('/MUserInfo/update-admin-status', ['i_id_user' => 3, 'i_admin' => 1, 'overwrite' => 1]);

        $this->assertResponseOk();
        $this->assertSame(1, (int)$table->get(3)->i_admin);
    }

    /**
     * 古い画面から「管理者を解除」されても、ブロック長権限を消さない。
     */
    public function testAdminOffDoesNotWipeBlockLeader(): void
    {
        $table = $this->getTableLocator()->get('MUserInfo');
        $table->updateAll(['i_admin' => 2], ['i_id_user' => 3]);

        $this->setAuthenticatedSession(true);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->post('/MUserInfo/update-admin-status', ['i_id_user' => 3, 'i_admin' => 0]);

        $this->assertResponseCode(409);
        $this->assertSame(2, (int)$table->get(3)->i_admin);
    }

    /**
     * 削除済みユーザーは、ログイン済みセッションでも使い続けられない（ログイン画面へ戻される）。
     */
    public function testDeletedUserSessionIsLoggedOut(): void
    {
        $this->session([
            'Auth' => [
                'i_id_user'       => 6,
                'c_login_account' => 'deleted_user',
                'c_user_name'     => '削除済みユーザー',
                'i_admin'         => 0,
                'i_user_level'    => 0,
            ],
        ]);
        $this->get('/MUserInfo/view/6');
        $this->assertRedirectContains('/MUserInfo/login');
    }

    private function postAddAs(int $adminValue, int $userLevel, array $extra = []): int
    {
        $table = $this->getTableLocator()->get('MUserInfo');
        $table->updateAll(['i_admin' => $adminValue, 'i_user_level' => $userLevel], ['i_id_user' => 2]);
        $this->session(['Auth' => [
            'i_id_user' => 2, 'c_login_account' => 'staff_user', 'c_user_name' => 'テスト',
            'i_admin' => $adminValue, 'i_user_level' => $userLevel, 'i_id_room' => 1,
        ]]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $before = $table->find()->count();
        $this->post('/MUserInfo/add', $extra + [
            'c_login_account' => 'reg_by_role',
            'c_login_passwd'  => 'password123',
            'c_user_name'     => '登録テスト',
            'role' => 1, 'age' => 10, 'age_group' => 1, 'i_user_gender' => 1,
            'MUserGroup' => [['i_id_room' => 1]],
        ]);

        return $table->find()->count() - $before;
    }

    /** 一般職員（i_admin=0, 職員）もユーザー登録できる。 */
    public function testGeneralStaffCanRegisterUser(): void
    {
        $this->assertSame(1, $this->postAddAs(0, 0));
    }

    /** ブロック長もユーザー登録できる。 */
    public function testBlockLeaderCanRegisterUser(): void
    {
        $this->assertSame(1, $this->postAddAs(2, 0));
    }

    /** 管理者以外は、職員(role=0)など児童以外を登録できない。 */
    public function testNonAdminCannotRegisterNonChild(): void
    {
        $this->assertSame(0, $this->postAddAs(2, 0, ['role' => 0, 'staff_id' => '900001']));
        $this->assertSame(0, $this->postAddAs(0, 0, ['role' => 3]));
    }

    /** 管理者以外は、自分が所属していない部屋へは登録できない（所属部屋は 1）。 */
    public function testNonAdminCannotRegisterToOtherRoom(): void
    {
        $this->assertSame(0, $this->postAddAs(2, 0, ['MUserGroup' => [['i_id_room' => 2]]]));
        $this->assertSame(1, $this->postAddAs(2, 0, ['c_login_account' => 'reg_own_room', 'MUserGroup' => [['i_id_room' => 1]]]));
    }

    private function postImportAs(int $adminValue, int $userLevel, array $records): array
    {
        $this->getTableLocator()->get('MUserInfo')->updateAll(['i_admin' => $adminValue, 'i_user_level' => $userLevel], ['i_id_user' => 2]);
        $this->session(['Auth' => [
            'i_id_user' => 2, 'c_login_account' => 'staff_user', 'c_user_name' => 'テスト',
            'i_admin' => $adminValue, 'i_user_level' => $userLevel, 'i_id_room' => 1,
        ]]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->post('/MUserInfo/import-json', ['records' => $records]);

        return (array)json_decode((string)$this->_response->getBody(), true);
    }

    /** 一括登録: 管理者以外は児童のみ・所属部屋のみ（コントローラー経由）。 */
    public function testImportJsonAsNonAdminIsLimitedToChildAndOwnRoom(): void
    {
        $json = $this->postImportAs(2, 0, [
            ['_row' => 1, 'login_id' => 'imp_staff', 'name' => 'A', 'role' => '職員', 'staff_id' => '900002'],
            ['_row' => 2, 'login_id' => 'imp_child', 'name' => 'B', 'role' => '児童', 'room_name1' => 'Lorem ipsum dolor sit amet'],
        ]);

        $this->assertResponseOk();
        $this->assertSame(1, $json['summary']['created'] ?? null);
        $this->assertSame(1, $json['summary']['failed'] ?? null);
        $t = $this->getTableLocator()->get('MUserInfo');
        $this->assertSame(0, $t->find()->where(['c_login_account' => 'imp_staff'])->count());
        $child = $t->find()->where(['c_login_account' => 'imp_child'])->firstOrFail();
        $this->assertSame(1, (int)$child->i_user_level);
        $this->assertContains((int)($child->i_admin ?? 0), [0]);
    }

    /** 一括登録: 児童は実行できない。 */
    public function testImportJsonAsChildIsForbidden(): void
    {
        $this->postImportAs(0, 1, [['_row' => 1, 'login_id' => 'imp_x', 'name' => 'A', 'role' => '児童']]);

        $this->assertResponseCode(403);
        $this->assertSame(0, $this->getTableLocator()->get('MUserInfo')->find()->where(['c_login_account' => 'imp_x'])->count());
    }

    /**
     * 登録権限を広げても、削除は管理者のみ。正例（管理者は削除できる）と対にして、
     * リクエストの不備による見かけ上の拒否でないことを担保する。
     */
    public function testDeleteIsStillAdminOnlyForStaffAndBlockLeader(): void
    {
        $t = $this->getTableLocator()->get('MUserInfo');

        foreach ([[0, 0], [2, 0]] as [$adm, $lvl]) {
            $t->updateAll(['i_del_flag' => 0], ['i_id_user' => 3]);
            $t->updateAll(['i_admin' => $adm, 'i_user_level' => $lvl], ['i_id_user' => 2]);
            $this->session(['Auth' => ['i_id_user' => 2, 'c_login_account' => 'staff_user', 'c_user_name' => 'テスト',
                'i_admin' => $adm, 'i_user_level' => $lvl, 'i_id_room' => 1]]);
            $this->enableCsrfToken();
            $this->enableSecurityToken();
            $this->post('/MUserInfo/delete/3');
            $this->assertSame(0, (int)$t->get(3)->i_del_flag, "i_admin=$adm は他人を削除できない");
        }

        // 正例: 管理者なら同じ形のリクエストで削除できる
        $t->updateAll(['i_del_flag' => 0], ['i_id_user' => 3]);
        $this->setAuthenticatedSession(true);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->post('/MUserInfo/delete/3');
        $this->assertSame(1, (int)$t->get(3)->i_del_flag);
    }

    /** 非管理者: 所属部屋がない場合、部屋を選ばない場合は登録できない（サーバー側で拒否）。 */
    public function testNonAdminWithoutRoomOrWithoutSelectionCannotRegister(): void
    {
        // 部屋を何も選ばない
        $this->assertSame(0, $this->postAddAs(2, 0, ['MUserGroup' => []]));

        // 所属部屋が無い職員（所属を無効化）
        $this->getTableLocator()->get('MUserGroup')->updateAll(['active_flag' => 1], ['i_id_user' => 2]);
        $this->assertSame(0, $this->postAddAs(0, 0, ['c_login_account' => 'no_room_staff']));
    }

    /** 管理者は部屋を選ばなくても登録できる（従来どおり）。 */
    public function testAdminCanRegisterWithoutRoom(): void
    {
        $this->assertSame(1, $this->postAddAs(1, 0, ['MUserGroup' => []]));
    }

    /** 管理者は任意の部屋へ登録できる（従来どおり）。 */
    public function testAdminCanRegisterToAnyRoom(): void
    {
        $this->assertSame(1, $this->postAddAs(1, 0, ['MUserGroup' => [['i_id_room' => 2]]]));
    }

    /** 管理者は職員も登録できる（従来どおり）。 */
    public function testAdminCanRegisterStaff(): void
    {
        $this->assertSame(1, $this->postAddAs(1, 0, ['role' => 0, 'staff_id' => '900001']));
    }

    /** 職員以外（児童など）は登録できない。 */
    public function testChildCannotRegisterUser(): void
    {
        $this->assertSame(0, $this->postAddAs(0, 1));
    }

    /** 非管理者が POST で権限・状態を指定しても反映されない（権限昇格の防止）。 */
    public function testRegisterIgnoresPrivilegedFieldsFromPost(): void
    {
        $this->assertSame(1, $this->postAddAs(0, 0, ['i_admin' => 3, 'i_enable' => 1, 'i_del_flag' => 1]));
        $created = $this->getTableLocator()->get('MUserInfo')->find()->where(['c_login_account' => 'reg_by_role'])->firstOrFail();
        $this->assertContains((int)($created->i_admin ?? 0), [0]);
        $this->assertSame(0, (int)$created->i_enable);
        $this->assertSame(0, (int)$created->i_del_flag);
    }

    /**
     * ナビの「ユーザ一覧」は、ユーザー登録ができる人（管理者・ブロック長・職員0）だけに出る。
     *
     * @return array<string, array{int, int|null, bool}>
     */
    public static function userListMenuProvider(): array
    {
        return [
            '管理者'         => [1, 0, true],
            'システム管理者' => [3, 0, true],
            'ブロック長'     => [2, 0, true],
            '職員(0)'        => [0, 0, true],
            '職員(7)'        => [0, 7, true],
            '児童'           => [0, 1, false],
            'その他'         => [0, 3, false],
            '役職未設定'     => [0, null, false],
        ];
    }

    /**
     * @dataProvider userListMenuProvider
     */
    public function testUserListMenuVisibility(int $iAdmin, ?int $userLevel, bool $visible): void
    {
        // 権限はセッション値ではなく DB の値で判定されるため、DB 側を更新する
        $this->getTableLocator()->get('MUserInfo')->updateAll(
            ['i_admin' => $iAdmin, 'i_user_level' => $userLevel],
            ['i_id_user' => 2]
        );
        $this->session([
            'Auth' => [
                'i_id_user'       => 2,
                'c_login_account' => 'staff_user',
                'c_user_name'     => 'テストユーザー',
                'i_admin'         => $iAdmin,
                'i_user_level'    => $userLevel,
                'i_id_room'       => 1,
            ],
        ]);
        $this->get('/MUserInfo');
        $this->assertResponseOk();
        $marker = '<i class="bi bi-people"></i>ユーザ一覧';
        $visible ? $this->assertResponseContains($marker) : $this->assertResponseNotContains($marker);

        // 入口が見える人は、登録画面にも実際に進める
        $this->get('/MUserInfo/add');
        $visible ? $this->assertResponseOk() : $this->assertRedirect(['controller' => 'MUserInfo', 'action' => 'index']);
    }

    private function setAuthenticatedSession(bool $isAdmin = true): void
    {
        $this->session([
            'Auth' => [
                'i_id_user'       => $isAdmin ? 1 : 2,
                'c_login_account' => $isAdmin ? 'admin_user' : 'staff_user',
                'c_user_name'     => 'テストユーザー',
                'i_admin'         => $isAdmin ? 1 : 0,
                'i_user_level'    => 0,
                'i_id_room'       => 1,
            ],
        ]);
    }
}
