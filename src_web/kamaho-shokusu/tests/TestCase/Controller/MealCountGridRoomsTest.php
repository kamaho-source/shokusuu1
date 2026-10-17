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
    }

    private function loginAs(int $iAdmin): void
    {
        // 権限はセッションではなく DB の値で判定されるため、DB 側も合わせる
        ConnectionManager::get('test')->update('m_user_info', ['i_admin' => $iAdmin], ['i_id_user' => 2]);
        $this->session(['Auth' => [
            'i_id_user' => 2, 'c_login_account' => 'staff_user', 'c_user_name' => '職員ユーザー',
            'i_admin' => $iAdmin, 'i_user_level' => 0,
        ]]);
    }

    /** @return array<string, array{0: int, 1: string}> */
    public static function cases(): array
    {
        return [
            '一般職員/各部屋'   => [0, 'room'],
            '一般職員/全部'     => [0, 'all'],
            'ブロック長/各部屋' => [2, 'room'],
            'ブロック長/全部'   => [2, 'all'],
        ];
    }

    /**
     * @dataProvider cases
     */
    public function testUserWithTwoRoomsSeesBothRooms(int $iAdmin, string $mode): void
    {
        $this->loginAs($iAdmin);
        $this->get('/TReservationInfo/meal-count-grid?mode=' . $mode);

        $this->assertResponseOk();
        $this->assertResponseContains('Lorem ipsum dolor sit amet'); // 部屋1
        $this->assertResponseContains('第二の部屋');                  // 部屋2
        $this->assertResponseNotContains('第三の部屋');               // 所属していない部屋は出ない
    }

    /** ブロック長にも「全部」モードが選択肢として出て、選択状態になる */
    public function testBlockLeaderCanSelectAllMode(): void
    {
        $this->loginAs(2);
        $this->get('/TReservationInfo/meal-count-grid?mode=all');

        $this->assertResponseOk();
        // 「全部」が選択肢にあり、選択状態になっている（空白の数には依存しない）
        $this->assertMatchesRegularExpression('/<option value="all"\s+selected>/', (string)$this->_response->getBody());
    }

    /**
     * エクセル食数予約は特例として、管理者にだけ削除済みの部屋も選択肢に出す（#819）。
     * 事務所ユーザーには出さない。
     */
    public function testDeletedRoomIsShownOnlyToAdmin(): void
    {
        $db = ConnectionManager::get('test');
        $db->insert('m_room_info', ['i_id_room' => 4, 'c_room_name' => '削除済みの部屋', 'i_disp_no' => 4, 'i_enable' => 1, 'i_del_flg' => 1]);

        $this->loginAs(1);
        $this->get('/TReservationInfo/meal-count-grid?mode=all');
        $this->assertResponseOk();
        $this->assertArrayHasKey(4, $this->viewVariable('allRooms'));

        // 事務所ユーザー（一般職員で、事務所の部屋に所属）には、削除済みの部屋に所属していても出さない
        $db->insert('m_room_info', ['i_id_room' => 5, 'c_room_name' => '事務所', 'i_disp_no' => 5, 'i_enable' => 1, 'i_del_flg' => 0]);
        foreach ([5, 4] as $roomId) {
            $db->insert('m_user_group', [
                'i_id_user' => 2, 'i_id_room' => $roomId, 'active_flag' => 0,
                'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't', 'dt_update' => '2026-01-01 00:00:00', 'c_update_user' => 't',
            ]);
        }
        $this->loginAs(0);
        $this->get('/TReservationInfo/meal-count-grid?mode=all');
        $this->assertResponseOk();
        $this->assertArrayNotHasKey(4, $this->viewVariable('allRooms'));
        $this->assertArrayHasKey(5, $this->viewVariable('allRooms'));
    }
}
