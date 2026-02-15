<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Controller\MUserInfoController;
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

    /**
     * Test index method
     *
     * @return void
     * @uses \App\Controller\MUserInfoController::index()
     */
    public function testIndex(): void
    {
        $this->setAuthenticatedSession();
        $this->get('/m-user-info');
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
        $this->get('/m-user-info/view/1');
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
        $this->post('/m-user-info/add', [
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
        $this->get('/m-user-info/edit/1');
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
        $this->post('/m-user-info/delete/1');
        $this->assertResponseSuccess();
        $this->assertRedirect(['action' => 'index']);
    }

    public function testRestoreAsAdmin(): void
    {
        $this->setAuthenticatedSession(true);
        $this->enableCsrfToken();
        $this->post('/m-user-info/restore/1');
        $this->assertResponseSuccess();
        $this->assertRedirect(['action' => 'index']);

        $user = $this->getTableLocator()->get('MUserInfo')->get(1);
        $this->assertSame(0, (int)$user->i_del_flag);
    }

    public function testRestoreAsNonAdminDenied(): void
    {
        $this->setAuthenticatedSession(false);
        $this->enableCsrfToken();
        $this->post('/m-user-info/restore/1');
        $this->assertResponseSuccess();
        $this->assertRedirect(['action' => 'index']);

        $user = $this->getTableLocator()->get('MUserInfo')->get(1);
        $this->assertSame(1, (int)$user->i_del_flag);
    }

    private function setAuthenticatedSession(bool $isAdmin = true): void
    {
        $this->session([
            'Auth' => [
                'i_id_user' => 1,
                'c_user_name' => 'テストユーザー',
                'i_admin' => $isAdmin ? 1 : 0,
                'i_user_level' => 0,
                'i_id_room' => 1,
            ],
        ]);
    }
}
