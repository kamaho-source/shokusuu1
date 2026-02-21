<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Controller\MRoomInfoController;
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
    ];

    /**
     * Test index method
     *
     * @return void
     * @uses \App\Controller\MRoomInfoController::index()
     */
    public function testIndex(): void
    {
        $this->setAuthenticatedSession();
        $this->get('/m-room-info');
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
        $this->get('/m-room-info/view/1');
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
        $this->post('/m-room-info/add', [
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
        $this->post('/m-room-info/edit/1', [
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
        $this->post('/m-room-info/delete/1');
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
                'i_id_user' => 1,
                'c_user_name' => 'テストユーザー',
                'i_admin' => 1,
                'i_user_level' => 0,
                'i_id_room' => 1,
            ],
        ]);
    }
}
