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
        $this->post('/MUserInfo/restore/1');
        $this->assertResponseSuccess();
        $this->assertRedirect(['action' => 'index']);

        $user = $this->getTableLocator()->get('MUserInfo')->get(1);
        $this->assertSame(0, (int)$user->i_del_flag);
    }

    public function testRestoreAsNonAdminDenied(): void
    {
        $this->setAuthenticatedSession(false);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->post('/MUserInfo/restore/1');
        $this->assertResponseSuccess();
        $this->assertRedirect(['action' => 'index']);

        $user = $this->getTableLocator()->get('MUserInfo')->get(1);
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

        $this->assertResponseFailure();

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

        $this->assertResponseFailure();

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
        // fixture の i_id_user=1 (admin_user) は i_del_flag=1（削除済み）
        $this->get('/MUserInfo/admin_change_password?user_id=1');
        $this->assertResponseOk();
        $this->assertNull($this->viewVariable('selectedUser'));
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
