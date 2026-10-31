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
        $this->assertRedirect(['action' => 'edit', 1]); // 保存後も編集画面に残る
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

    /** 部屋情報の編集フォームの［保存］と同じ形で送る（user_ids[] は画面の並び順） */
    private function postReorder(array $login, array $userIds, int $roomId = 1, array $extra = []): void
    {
        $this->loginAs(...$login);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->post('/MRoomInfo/edit/' . $roomId, ['user_ids' => $userIds] + $extra);
    }

    private function dispNo(int $userId): ?int
    {
        return $this->getTableLocator()->get('MUserInfo')->get($userId)->i_disp_no;
    }

    public function testSaveByAdminUpdatesNameAndOrderTogether(): void
    {
        $this->postReorder([1, 1, 0], [2, 1, 3], 1, ['c_room_name' => '新しい部屋名']);

        $this->assertRedirect(['action' => 'edit', 1]); // 保存後も編集画面に残る
        $this->assertSame([2, 1], [$this->dispNo(1), $this->dispNo(2)]);
        $this->assertSame('新しい部屋名', $this->getTableLocator()->get('MRoomInfo')->get(1)->c_room_name);
    }

    /** 所属部屋の職員は並び順だけ保存できる（部屋名は送っても変わらない） */
    public function testSaveByStaffOfRoomUpdatesOrderOnly(): void
    {
        $before = $this->getTableLocator()->get('MRoomInfo')->get(1)->c_room_name;
        $this->postReorder([2, 0, 0], [2, 1, 3], 1, ['c_room_name' => '変更されてはいけない']);

        $this->assertRedirect(['action' => 'edit', 1]); // 保存後も編集画面に残る
        $this->assertSame(1, $this->dispNo(2));
        $this->assertSame($before, $this->getTableLocator()->get('MRoomInfo')->get(1)->c_room_name);
    }

    public function testSaveByStaffOutsideRoomIsForbidden(): void
    {
        $this->postReorder([5, 0, 0], [2, 1, 3]);

        $this->assertRedirectContains('/MRoomInfo');
        $this->assertSame(1, $this->dispNo(1));
    }

    public function testSaveByChildIsForbidden(): void
    {
        $this->postReorder([3, 0, 1], [2, 1, 3]);

        $this->assertRedirectContains('/MRoomInfo');
        $this->assertSame(1, $this->dispNo(1));
    }

    public function testSaveRejectsNonMemberAndKeepsName(): void
    {
        $before = $this->getTableLocator()->get('MRoomInfo')->get(1)->c_room_name;
        $this->enableRetainFlashMessages();
        $this->postReorder([1, 1, 0], [2, 1, 3, 4], 1, ['c_room_name' => '変更されてはいけない']);

        $this->assertRedirectContains('/MRoomInfo/edit/1');
        $this->assertFlashMessage('部屋の所属メンバーが変わっています。画面を読み込み直してください。');
        $this->assertSame(1, $this->dispNo(1));
        $this->assertSame($before, $this->getTableLocator()->get('MRoomInfo')->get(1)->c_room_name);
    }

    /** 削除済みの部屋は並び替えの対象にしない（管理者が送っても並びは変わらない） */
    public function testSaveOnDeletedRoomIgnoresOrder(): void
    {
        $this->getTableLocator()->get('MRoomInfo')->updateAll(['i_del_flg' => 1], ['i_id_room' => 1]);
        $this->postReorder([1, 1, 0], [2, 1, 3]);

        $this->assertSame(1, $this->dispNo(1));
    }

    /** 並び替えは編集画面（部屋名と同じカード・同じ［保存］）。部屋情報（閲覧）は一覧だけ */
    public function testReorderControlsAreOnEditScreenOnly(): void
    {
        $this->loginAs(1, 1, 0);
        $this->get('/MRoomInfo/view/1');
        $this->assertResponseOk();
        $this->assertResponseNotContains('name="user_ids[]"');
        $this->assertResponseContains('/MRoomInfo/edit/1');

        $this->get('/MRoomInfo/edit/1');
        $this->assertResponseOk();
        $this->assertResponseContains('name="user_ids[]"');
        $this->assertResponseContains('name="c_room_name"');
    }

    /** 編集画面の並び替えは区分で分けず、職員・児童を1つの表で表示順に並べる */
    public function testEditListsAllLevelsInOneTableByDisplayOrder(): void
    {
        $this->getTableLocator()->get('MUserInfo')->updateAll(['i_disp_no' => 10], ['i_id_user' => 1]);
        $this->loginAs(1, 1, 0);
        $this->get('/MRoomInfo/edit/1');
        $this->assertResponseOk();

        $body = (string)$this->_response->getBody();
        $this->assertStringNotContainsString('class="grp"', $body);
        $this->assertSame(1, substr_count($body, '<tbody'));
        preg_match_all('/<tr data-user-id="(\d+)"/', $body, $m);
        $this->assertSame(['2', '3', '1'], $m[1], '児童3 が職員1 より前に出る');
        // 「子どもだけ年齢順」用に、子どもの行だけ印を付ける
        $this->assertMatchesRegularExpression('/<tr data-user-id="3"[^>]*data-child="1"/', $body);
        $this->assertDoesNotMatchRegularExpression('/<tr data-user-id="2"[^>]*data-child/', $body);
    }

    /** 区分が未設定の人は、詳細画面でも編集画面でも「未設定」と出る（区分名は UserRole::levelLabel で共通） */
    public function testUnsetLevelIsLabeledSameOnViewAndEdit(): void
    {
        $this->getTableLocator()->get('MUserInfo')->updateAll(['i_user_level' => null], ['i_id_user' => 2]);
        $this->loginAs(1, 1, 0);
        foreach (['/MRoomInfo/view/1', '/MRoomInfo/edit/1'] as $url) {
            $this->get($url);
            $this->assertResponseOk();
            $this->assertResponseContains('未設定', $url);
        }
    }

    /** 所属部屋の職員は編集画面を開け、部屋名は表示だけ */
    public function testStaffOfRoomCanOpenEditForReorderOnly(): void
    {
        $this->loginAs(2, 0, 0);
        $this->get('/MRoomInfo/view/1');
        $this->assertResponseContains('メンバーの並び替え');

        $this->get('/MRoomInfo/edit/1');
        $this->assertResponseOk();
        $this->assertResponseContains('name="user_ids[]"');
        $this->assertResponseNotContains('name="c_room_name"');
    }

    /** 児童・所属外の職員は編集画面を開けない */
    public function testChildAndOutsideStaffCannotOpenEdit(): void
    {
        $this->loginAs(3, 0, 1);
        $this->get('/MRoomInfo/edit/1');
        $this->assertRedirectContains('/MRoomInfo');

        $this->loginAs(5, 0, 0);
        $this->get('/MRoomInfo/view/1');
        $this->assertResponseNotContains('メンバーの並び替え');
        $this->get('/MRoomInfo/edit/1');
        $this->assertRedirectContains('/MRoomInfo');
    }
}
