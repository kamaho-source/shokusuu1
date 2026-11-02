<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * RoomUsageController 統合テスト（システム管理者専用の部屋使用率）
 */
class RoomUsageControllerTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TIndividualReservationInfo',
    ];

    private function login(int $adminLevel): void
    {
        $this->session(['Auth' => [
            'i_id_user' => $adminLevel === 3 ? 4 : 1,
            'c_login_account' => $adminLevel === 3 ? 'system_admin_user' : 'admin_user',
            'c_user_name' => 'テスト', 'i_admin' => $adminLevel, 'i_user_level' => 0, 'i_id_room' => 1,
        ]]);
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return json_decode((string)$this->_response->getBody(), true);
    }

    private function insertDinner(int $userId, string $date): void
    {
        ConnectionManager::get('test')->insert('t_individual_reservation_info', [
            'i_id_user' => $userId, 'd_reservation_date' => $date, 'i_reservation_type' => 3, 'i_id_room' => 1,
            'eat_flag' => 0, 'i_change_flag' => 1, 'i_version' => 1,
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't',
        ]);
    }

    public function testIndex_systemAdmin_setsFilterDefaultsAndData(): void
    {
        $this->login(3);
        $this->get('/RoomUsage');

        $this->assertResponseOk();
        $this->assertSame(50.0, $this->viewVariable('threshold'));
        $this->assertNull($this->viewVariable('mealType'));
        $this->assertSame(date('Y-m-01'), $this->viewVariable('dateFrom'));
        $this->assertNotEmpty($this->viewVariable('rooms'));
    }

    public function testIndex_usesGivenFilters(): void
    {
        $this->login(3);
        $this->get('/RoomUsage?date_from=2026-03-01&date_to=2026-03-02&meal_type=3&threshold=80.5');

        $this->assertResponseOk();
        $this->assertSame(3, $this->viewVariable('mealType'));
        $this->assertSame(80.5, $this->viewVariable('threshold'));
        $this->assertSame('2026-03-01', $this->viewVariable('dateFrom'));
    }

    /** 画面の「使用率の低い部屋」は、しきい値以下の部屋だけ */
    public function testIndex_lowRoomsAreAtOrBelowThreshold(): void
    {
        $this->insertDinner(3, '2026-03-01');
        $this->login(3);

        $this->get('/RoomUsage?date_from=2026-03-01&date_to=2026-03-01&meal_type=3&threshold=0');
        $this->assertNotEmpty($this->viewVariable('rooms'));
        $this->assertSame([], $this->viewVariable('lowRooms'), '部屋1は 33.3% なので 0% 以下に入らない');

        $this->get('/RoomUsage?date_from=2026-03-01&date_to=2026-03-01&meal_type=3&threshold=33.3');
        $this->assertSame([1], array_column($this->viewVariable('lowRooms'), 'room_id'));
    }

    public function testIndex_admin_redirectedWithFlash(): void
    {
        $this->login(1);
        $this->get('/RoomUsage');

        $this->assertHeader('Location', 'http://localhost/');
        $this->assertFlashMessage('この機能はシステム管理者のみ利用できます。', 'flash');
    }

    public function testRoomUsage_returnsRoomsWithRate(): void
    {
        // 部屋1 の在籍者は user 1,2,3 の3人（削除済みの user 6 は、所属行が現役のままでも数えない。以前は分母に入っていた）。2日 × 夕食のみ → 分母 6、予約 1 件
        $this->insertDinner(3, '2026-03-01');
        $this->login(3);
        $this->get('/RoomUsage/roomUsage?date_from=2026-03-01&date_to=2026-03-02&meal_type=3');

        $this->assertResponseOk();
        $room = $this->json()['rooms'][0];
        $this->assertSame(1, $room['room_id']);
        $this->assertSame(6, $room['capacity']);
        $this->assertSame(1, $room['eat_count']);
        $this->assertSame(16.7, $room['usage_rate']);
    }

    public function testRoomUsage_emptyMealTypeMeansAllMealTypes(): void
    {
        $this->login(3);
        $this->get('/RoomUsage/roomUsage?date_from=2026-03-01&date_to=2026-03-01&meal_type=');

        $this->assertResponseOk();
        // 3人 × 1日 × 4食
        $this->assertSame(12, $this->json()['rooms'][0]['capacity']);
    }

    /**
     * 期間・食事・しきい値が読めないときは 400（以前は 500、期間が逆なら使用率 0% の表、食事 0 は空の表）
     *
     * @return array<string, array{string}>
     */
    public static function badFilterProvider(): array
    {
        return [
            '日付でない' => ['date_from=abc&date_to=2026-03-02'],
            '存在しない日付' => ['date_from=2026-02-30&date_to=2026-03-02'],
            '期間が逆' => ['date_from=2026-03-05&date_to=2026-03-01'],
            '食事が範囲外' => ['meal_type=9'],
            '食事が数値でない' => ['meal_type=abc'],
            '食事が小数' => ['meal_type=1.5'],
            'しきい値が数値でない' => ['threshold=abc'],
        ];
    }

    /**
     * @dataProvider badFilterProvider
     */
    public function testBadFilters_return400(string $query): void
    {
        $this->login(3);
        foreach (['/RoomUsage/roomUsage?', '/RoomUsage/lowUsageRooms?'] as $url) {
            $this->get($url . $query);
            $this->assertResponseCode(400, $url);
            $this->assertFalse($this->json()['success']);
        }
        $this->enableRetainFlashMessages();
        $this->get('/RoomUsage?' . $query);
        $this->assertResponseCode(400);
        $this->assertSame([], $this->viewVariable('rooms'));
        $this->assertFlashElement('flash/error');
    }

    /** 配列のクエリは「指定なし」と同じ（警告を出さない） */
    public function testArrayFilters_treatedAsMissing(): void
    {
        $this->login(3);
        $this->get('/RoomUsage/roomUsage?date_from[]=x&meal_type[]=1');
        $this->assertResponseOk();
    }

    public function testRoomUsage_admin_returns403Json(): void
    {
        $this->login(1);
        $this->get('/RoomUsage/roomUsage');

        $this->assertResponseCode(403);
        $this->assertFalse($this->json()['success']);
    }

    public function testLowUsageRooms_threshold(): void
    {
        $this->insertDinner(3, '2026-03-01');
        $this->login(3);

        $this->get('/RoomUsage/lowUsageRooms?threshold=20&date_from=2026-03-01&date_to=2026-03-02&meal_type=3');
        $this->assertResponseOk();
        $json = $this->json();
        $this->assertEquals(20.0, $json['threshold']);
        $this->assertSame([1], array_column($json['rooms'], 'room_id'));

        // 使用率 12.5% は 10% 以下ではない
        $this->get('/RoomUsage/lowUsageRooms?threshold=10&date_from=2026-03-01&date_to=2026-03-02&meal_type=3');
        $this->assertSame([], $this->json()['rooms']);
    }

    public function testLowUsageRooms_defaultThreshold50(): void
    {
        $this->login(3);
        $this->get('/RoomUsage/lowUsageRooms?threshold=');

        $this->assertResponseOk();
        $this->assertEquals(50.0, $this->json()['threshold']);
    }

    public function testLowUsageRooms_admin_returns403Json(): void
    {
        $this->login(1);
        $this->get('/RoomUsage/lowUsageRooms');
        $this->assertResponseCode(403);
    }
}
