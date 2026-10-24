<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * 実食保存API: 存在しない・削除済みの部屋、所属外の部屋には書き込めない。
 */
class ActualMealSaveRoomsTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TIndividualReservationInfo',
    ];

    private mixed $original = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->original = Configure::read('Features.actualMeal');
        Configure::write('Features.actualMeal', true);
        $db = ConnectionManager::get('test');
        foreach ([[60, 0], [61, 1]] as [$id, $del]) { // 60=有効・未所属、61=削除済み
            $db->insert('m_room_info', ['i_id_room' => $id, 'c_room_name' => '部屋' . $id, 'i_disp_no' => $id, 'i_enable' => 1, 'i_del_flg' => $del]);
        }
        $db->insert('m_user_group', [
            'i_id_user' => 3, 'i_id_room' => 61, 'active_flag' => 0,
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't', 'dt_update' => '2026-01-01 00:00:00', 'c_update_user' => 't',
        ]);
    }

    protected function tearDown(): void
    {
        Configure::write('Features.actualMeal', $this->original);
        parent::tearDown();
    }

    private function save(int $userId, int $actorId, int $roomId): void
    {
        // セッションの identity は c_login_account で DB から引き直されるため、実在のアカウント名を使う
        $account = [1 => 'admin_user', 3 => 'non_staff_user'][$actorId];
        $this->session(['Auth' => ['i_id_user' => $actorId, 'c_login_account' => $account, 'c_user_name' => 'a', 'i_admin' => 0, 'i_user_level' => 0]]);
        $this->enableCsrfToken();
        $this->post('/TReservationInfo/actual-meal-save', [
            'room_id' => $roomId, 'date' => date('Y-m-d'), 'meal_type' => 2, 'user_id' => $userId, 'checked' => 1, 'version' => 1,
        ]);
    }

    private function savedRows(int $userId, int $roomId): int
    {
        return (int)ConnectionManager::get('test')->execute(
            'SELECT COUNT(*) FROM t_individual_reservation_info WHERE i_id_user = ? AND i_id_room = ?',
            [$userId, $roomId]
        )->fetchColumn(0);
    }

    public function testChildCanSaveOwnActiveRoom(): void
    {
        $this->save(3, 3, 1); // 正例（user 3 は部屋1に所属）

        $this->assertResponseOk();
        $this->assertSame(1, $this->savedRows(3, 1));
    }

    public function testChildCannotSaveNotMemberRoom(): void
    {
        $this->save(3, 3, 60);

        $this->assertResponseCode(403);
        $this->assertSame(0, $this->savedRows(3, 60));
    }

    public function testChildCannotSaveDeletedRoomEvenWhenMember(): void
    {
        $this->save(3, 3, 61);

        $this->assertResponseCode(403);
        $this->assertSame(0, $this->savedRows(3, 61));
    }

    public function testAdminCannotSaveDeletedMissingOrNotMemberRoom(): void
    {
        ConnectionManager::get('test')->update('m_user_info', ['i_admin' => 1], ['i_id_user' => 1]);

        $this->save(1, 1, 61);
        $this->assertResponseCode(403);
        $this->save(1, 1, 9999);
        $this->assertResponseCode(403);
        $this->save(1, 1, 60); // 管理者も所属外の部屋には書けない（#821）
        $this->assertResponseCode(403);
        $this->assertSame(0, $this->savedRows(1, 60));
    }

    public function testAdminCanProxySaveInOwnRoom(): void
    {
        ConnectionManager::get('test')->update('m_user_info', ['i_admin' => 1], ['i_id_user' => 1]);

        $this->save(3, 1, 1); // 管理者(user 1)が、同じ部屋1に所属する児童(user 3)の実食を代理入力
        $this->assertResponseOk();
        $this->assertSame(1, $this->savedRows(3, 1));
    }
}
