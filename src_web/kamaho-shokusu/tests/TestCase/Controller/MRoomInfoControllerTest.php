<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Controller\MRoomInfoController;
use Cake\Core\Configure;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * App\Controller\MRoomInfoController Test Case
 *
 * @uses \App\Controller\MRoomInfoController
 */
class MRoomInfoControllerTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * Fixtures
     *
     * @var list<string>
     */
    protected array $fixtures = [
        'app.MRoomInfo',
        'app.MUserInfo',
        'app.MUserGroup',
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
     * @uses \App\Controller\MRoomInfoController::index()
     */
    public function testIndex(): void
    {
        $this->setAuthenticatedSession();
        $this->get('/MRoomInfo');
        $this->assertResponseOk();
    }

    /**
     * Test view method
     *
     * @return void
     * @uses \App\Controller\MRoomInfoController::view()
     */
    public function testView(): void
    {
        $this->setAuthenticatedSession();
        $this->get('/MRoomInfo/view/1');
        $this->assertResponseOk();
    }

    /**
     * Test add method
     *
     * @return void
     * @uses \App\Controller\MRoomInfoController::add()
     */
    public function testAdd(): void
    {
        $this->setAuthenticatedSession();
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->post('/MRoomInfo/add', [
            'c_room_name' => 'テスト部屋',
            'i_enable' => 1,
        ]);
        $this->assertResponseSuccess();
        $this->assertRedirect(['action' => 'index']);
    }

    /**
     * Test edit method
     *
     * @return void
     * @uses \App\Controller\MRoomInfoController::edit()
     */
    public function testEdit(): void
    {
        $this->setAuthenticatedSession();
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->post('/MRoomInfo/edit/1', [
            'c_room_name' => '編集済み部屋',
            'i_enable' => 1,
        ]);
        $this->assertResponseSuccess();
        $this->assertRedirect(['action' => 'index']);
    }

    /**
     * Test delete method
     *
     * @return void
     * @uses \App\Controller\MRoomInfoController::delete()
     */
    public function testDelete(): void
    {
        $this->setAuthenticatedSession();
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->post('/MRoomInfo/delete/1');
        $this->assertResponseSuccess();
        $this->assertRedirect(['action' => 'index']);

        $table = $this->getTableLocator()->get('MRoomInfo');
        $room = $table->get(1);
        $this->assertSame(1, (int)$room->i_del_flg);
    }

    private function setAuthenticatedSession(): void
    {
        $this->session([
            'Auth' => [
                'i_id_user'       => 1,
                'c_login_account' => 'admin_user',
                'c_user_name'     => 'テストユーザー',
                'i_admin'         => 1,
                'i_user_level'    => 0,
                'i_id_room'       => 1,
            ],
        ]);
    }

    /** 削除済みの部屋の詳細は開かず、一覧へ戻す（#819） */
    public function testViewDeletedRoomRedirects(): void
    {
        $this->getTableLocator()->get('MRoomInfo')->updateAll(['i_del_flg' => 1], ['i_id_room' => 1]);
        $this->setAuthenticatedSession();
        $this->enableRetainFlashMessages();

        $this->get('/MRoomInfo/view/1');

        $this->assertRedirectContains('/MRoomInfo');
        $this->assertFlashMessage('この部屋は削除されています。');
    }

    /** 削除フラグが NULL の部屋は一覧（i_del_flg = 0）に出ないので、詳細も開かない（#819） */
    public function testViewNullDeleteFlagRoomRedirects(): void
    {
        $this->getTableLocator()->get('MRoomInfo')->updateAll(['i_del_flg' => null], ['i_id_room' => 1]);
        $this->setAuthenticatedSession();
        $this->enableRetainFlashMessages();

        $this->get('/MRoomInfo/view/1');

        $this->assertRedirectContains('/MRoomInfo');
        $this->assertFlashMessage('この部屋は削除されています。');
    }

    /* ---------- 所属メンバーの並び替え（#871） ---------- */

    /** DB の値で権限を判定するため、ロールは DB 側に設定してからそのユーザーでログインする */
    private function loginAs(int $userId, int $iAdmin, int $userLevel): void
    {
        $users = $this->getTableLocator()->get('MUserInfo');
        $users->updateAll(['i_admin' => $iAdmin, 'i_user_level' => $userLevel], ['i_id_user' => $userId]);
        $this->session(['Auth' => $users->get($userId)->toArray()]);
    }

    private function postReorder(array $login, array $userIds, int $roomId = 1): void
    {
        $this->loginAs(...$login);
        $this->enableCsrfToken();
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
        $this->post('/MRoomInfo/reorderMembers/' . $roomId, ['user_ids' => $userIds]);
    }

    private function dispNo(int $userId): ?int
    {
        return $this->getTableLocator()->get('MUserInfo')->get($userId)->i_disp_no;
    }

    public function testReorderMembersByAdminSaves(): void
    {
        $this->postReorder([1, 1, 0], [2, 1, 3]);

        $this->assertResponseOk();
        $this->assertSame(2, json_decode((string)$this->_response->getBody(), true)['changed']);
        $this->assertSame([2, 1], [$this->dispNo(1), $this->dispNo(2)]);
    }

    public function testReorderMembersByStaffOfRoomSaves(): void
    {
        $this->postReorder([2, 0, 0], [2, 1, 3]);

        $this->assertResponseOk();
        $this->assertSame(1, $this->dispNo(2));
    }

    public function testReorderMembersByStaffOutsideRoomIsForbidden(): void
    {
        $this->postReorder([5, 0, 0], [2, 1, 3]);

        $this->assertResponseCode(403);
        $this->assertSame(1, $this->dispNo(1));
    }

    public function testReorderMembersByChildIsForbidden(): void
    {
        $this->postReorder([3, 0, 1], [2, 1, 3]);

        $this->assertResponseCode(403);
        $this->assertSame(1, $this->dispNo(1));
    }

    public function testReorderMembersRejectsNonMember(): void
    {
        $this->postReorder([1, 1, 0], [2, 1, 3, 4]);

        $this->assertResponseCode(422);
        $this->assertSame(1, $this->dispNo(1));
    }

    public function testReorderMembersOnDeletedRoomIsNotFound(): void
    {
        $this->getTableLocator()->get('MRoomInfo')->updateAll(['i_del_flg' => 1], ['i_id_room' => 1]);
        $this->postReorder([1, 1, 0], [2, 1, 3]);

        $this->assertResponseCode(404);
    }

    public function testReorderMembersRejectsGet(): void
    {
        $this->setAuthenticatedSession();
        $this->get('/MRoomInfo/reorderMembers/1');

        $this->assertResponseCode(405);
    }

    public function testViewShowsReorderControlsOnlyWhenAllowed(): void
    {
        $this->setAuthenticatedSession();
        $this->get('/MRoomInfo/view/1');
        $this->assertResponseContains('id="member-order-save"');

        $this->loginAs(3, 0, 1);
        $this->get('/MRoomInfo/view/1');
        $this->assertResponseOk();
        $this->assertResponseNotContains('id="member-order-save"');
    }

    /** 画面の JS と同じく JSON ボディ + X-CSRF-Token ヘッダーで送っても保存できる */
    public function testReorderMembersAcceptsJsonBody(): void
    {
        $this->loginAs(1, 1, 0);
        $token = (new \Cake\Http\Middleware\CsrfProtectionMiddleware())->createToken();
        $this->cookie('csrfToken', $token);
        $this->configRequest(['headers' => [
            'Accept' => 'application/json', 'Content-Type' => 'application/json', 'X-CSRF-Token' => $token,
        ]]);
        $this->post('/MRoomInfo/reorderMembers/1', (string)json_encode(['user_ids' => [2, 1, 3]]));

        $this->assertResponseOk();
        $this->assertSame([2, 1], [$this->dispNo(1), $this->dispNo(2)]);
    }
}
