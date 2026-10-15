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
