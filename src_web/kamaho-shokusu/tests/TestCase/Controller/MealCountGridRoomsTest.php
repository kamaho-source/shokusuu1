<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * エクセル食数予約: 複数部屋に所属する職員に、所属している部屋がすべて出ること。
 */
class MealCountGridRoomsTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TIndividualReservationInfo',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $db = ConnectionManager::get('test');
        foreach ([[2, '第二の部屋'], [3, '第三の部屋']] as [$id, $name]) {
            $db->insert('m_room_info', ['i_id_room' => $id, 'c_room_name' => $name, 'i_disp_no' => $id, 'i_enable' => 1, 'i_del_flg' => 0]);
        }
        // 職員(user 2)を部屋1・2に所属させる（部屋3には所属しない）
        $db->insert('m_user_group', [
            'i_id_user' => 2, 'i_id_room' => 2, 'active_flag' => 0,
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't', 'dt_update' => '2026-01-01 00:00:00', 'c_update_user' => 't',
        ]);
        $this->session(['Auth' => [
            'i_id_user' => 2, 'c_login_account' => 'staff_user', 'c_user_name' => '職員ユーザー',
            'i_admin' => 0, 'i_user_level' => 0,
        ]]);
    }

    /** @return array<string, array{0: string}> */
    public static function modes(): array
    {
        return ['各部屋' => ['room'], '全部' => ['all']];
    }

    /**
     * @dataProvider modes
     */
    public function testStaffWithTwoRoomsSeesBothRooms(string $mode): void
    {
        $this->get('/TReservationInfo/meal-count-grid?mode=' . $mode);

        $this->assertResponseOk();
        $this->assertResponseContains('Lorem ipsum dolor sit amet'); // 部屋1
        $this->assertResponseContains('第二の部屋');                  // 部屋2
        $this->assertResponseNotContains('第三の部屋');               // 所属していない部屋は出ない
    }
}
