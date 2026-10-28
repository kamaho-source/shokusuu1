<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Datasource\ConnectionManager;
use Cake\Http\Middleware\CsrfProtectionMiddleware;
use Cake\I18n\Date;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * ReservationToggleController（単件 toggle・一括 bulkToggle）のテスト。
 *
 * 退出済み部屋の取消など部屋変更まわりは ReservationRoomChangeApiTest 側で検証済み。
 */
class ReservationToggleControllerTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.MRoomInfo',
        'app.MUserInfo',
        'app.MUserGroup',
        'app.TIndividualReservationInfo',
        'app.TReservationInfo',
        'app.TAuditLog',
    ];

    /** @param array{0:int,1:string,2:int,3:int} $who [id, account, admin, level] */
    private function login(array $who): void
    {
        $this->session(['Auth' => [
            'i_id_user' => $who[0], 'c_login_account' => $who[1], 'c_user_name' => 'テスト' . $who[0],
            'i_admin' => $who[2], 'i_user_level' => $who[3], 'i_id_room' => 1,
        ]]);
        $this->enableCsrfToken();
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
    }

    private function loginChild(): void
    {
        $this->login([3, 'non_staff_user', 0, 1]);
    }

    private function loginAdmin(): void
    {
        $this->login([1, 'admin_user', 1, 0]);
    }

    private function farDate(): string
    {
        return Date::today()->addDays(30)->format('Y-m-d');
    }

    /** @return array<string, mixed> */
    private function json(): array
    {
        return (array)json_decode((string)$this->_response->getBody(), true);
    }

    private function eatFlag(int $userId, int $type): ?int
    {
        $row = $this->getTableLocator()->get('TIndividualReservationInfo')->find()
            ->where(['i_id_user' => $userId, 'i_id_room' => 1, 'd_reservation_date' => $this->farDate(), 'i_reservation_type' => $type])
            ->first();

        return $row === null ? null : (int)$row->eat_flag;
    }

    public function testToggle_registersAndCancelsOwnMeal(): void
    {
        $this->loginChild();
        $this->post('/TReservationInfo/toggle/1', ['date' => $this->farDate(), 'meal' => 1, 'value' => 1, 'userId' => 3]);

        $this->assertResponseOk();
        $this->assertTrue($this->json()['ok']);
        $this->assertSame(1, $this->eatFlag(3, 1));

        $this->post('/TReservationInfo/toggle/1', ['date' => $this->farDate(), 'meal' => 1, 'value' => 0, 'userId' => 3]);
        $this->assertResponseOk();
        $this->assertSame(0, $this->eatFlag(3, 1));
    }

    public function testToggle_roomIdInBodyIsUsedWhenNotInUrl(): void
    {
        $this->loginChild();
        $this->post('/TReservationInfo/toggle', ['roomId' => 1, 'date' => $this->farDate(), 'meal' => 3, 'value' => 1, 'userId' => 3]);

        $this->assertResponseOk();
        $this->assertSame(1, $this->eatFlag(3, 3));
    }

    public function testToggle_acceptsRawJsonBody(): void
    {
        $token = (new CsrfProtectionMiddleware())->createToken();
        $this->session(['Auth' => [
            'i_id_user' => 3, 'c_login_account' => 'non_staff_user', 'c_user_name' => 'テスト3',
            'i_admin' => 0, 'i_user_level' => 1, 'i_id_room' => 1,
        ]]);
        $this->cookie('csrfToken', $token);
        $this->configRequest([
            'headers' => ['Accept' => 'application/json', 'X-CSRF-Token' => $token],
            'input'   => json_encode(['i_id_room' => 1, 'date' => $this->farDate(), 'meal' => 2, 'value' => 1, 'userId' => 3]),
        ]);
        $this->post('/TReservationInfo/toggle');

        $this->assertResponseOk();
        $this->assertSame(1, $this->eatFlag(3, 2));
    }

    public function testToggle_withoutRoomReturns400(): void
    {
        $this->loginChild();
        $this->post('/TReservationInfo/toggle', ['date' => $this->farDate(), 'meal' => 1, 'value' => 1]);

        $this->assertResponseCode(400);
        $this->assertResponseContains('roomId is required.');
    }

    public function testToggle_otherRoomIsForbidden(): void
    {
        ConnectionManager::get('test')->insert('m_room_info', [
            'i_id_room' => 2, 'c_room_name' => '部屋2', 'i_disp_no' => 2, 'i_enable' => 1, 'i_del_flg' => 0,
            'dt_create' => '2024-07-29 09:11:06', 'c_create_user' => 't',
        ]);
        $this->loginChild();
        $this->post('/TReservationInfo/toggle/2', ['date' => $this->farDate(), 'meal' => 1, 'value' => 1]);

        $this->assertResponseCode(403);
        $this->assertFalse($this->getTableLocator()->get('TIndividualReservationInfo')->exists(['i_id_user' => 3, 'i_id_room' => 2]));
    }

    public function testToggle_domainErrorIsReturnedWithItsStatus(): void
    {
        $this->loginChild();
        $this->post('/TReservationInfo/toggle/1', ['date' => 'not-a-date', 'meal' => 1, 'value' => 1]);

        $this->assertTrue($this->_response->getStatusCode() >= 400);
        $this->assertFalse($this->json()['ok']);
        $this->assertFalse($this->getTableLocator()->get('TIndividualReservationInfo')->exists(['i_id_user' => 3]));
    }

    public function testBulkToggle_savesOkItemsAndReportsFailures(): void
    {
        $this->loginAdmin();
        $this->post('/TReservationInfo/bulk-toggle', ['items' => [
            ['roomId' => 1, 'userId' => 3, 'date' => $this->farDate(), 'meal' => 1, 'value' => 1],
            ['roomId' => 1, 'userId' => 3, 'date' => 'bad-date', 'meal' => 1, 'value' => 1],
            ['roomId' => 1, 'userId' => 2, 'date' => $this->farDate(), 'meal' => 3, 'value' => 1],
        ]]);

        $this->assertResponseOk();
        $data = $this->json()['data'];
        $this->assertSame(2, $data['ok']);
        $this->assertSame(1, $data['fail']);
        $this->assertTrue($data['results'][0]['ok']);
        $this->assertFalse($data['results'][1]['ok']);
        $this->assertTrue($data['results'][2]['ok']);
        $this->assertSame(1, $this->eatFlag(3, 1));
        $this->assertSame(1, $this->eatFlag(2, 3));
    }

    public function testBulkToggle_cancelIsProcessedBeforeRegistrationAndResultsKeepOrder(): void
    {
        $this->loginAdmin();
        $this->post('/TReservationInfo/bulk-toggle', ['items' => [
            ['roomId' => 1, 'userId' => 3, 'date' => $this->farDate(), 'meal' => 1, 'value' => 1],
        ]]);
        $this->assertResponseOk();

        $this->post('/TReservationInfo/bulk-toggle', ['items' => [
            ['roomId' => 1, 'userId' => 3, 'date' => $this->farDate(), 'meal' => 3, 'value' => 1],
            ['roomId' => 1, 'userId' => 3, 'date' => $this->farDate(), 'meal' => 1, 'value' => 0],
        ]]);

        $this->assertResponseOk();
        $data = $this->json()['data'];
        $this->assertSame(2, $data['ok']);
        $this->assertSame(0, $this->eatFlag(3, 1));
        $this->assertSame(1, $this->eatFlag(3, 3));
    }

    public function testBulkToggle_acceptsRawJsonBody(): void
    {
        $token = (new CsrfProtectionMiddleware())->createToken();
        $this->session(['Auth' => [
            'i_id_user' => 1, 'c_login_account' => 'admin_user', 'c_user_name' => 'テスト1',
            'i_admin' => 1, 'i_user_level' => 0, 'i_id_room' => 1,
        ]]);
        $this->cookie('csrfToken', $token);
        $this->configRequest([
            'headers' => ['Accept' => 'application/json', 'X-CSRF-Token' => $token],
            'input'   => json_encode(['items' => [['roomId' => 1, 'userId' => 3, 'date' => $this->farDate(), 'meal' => 2, 'value' => 1]]]),
        ]);
        $this->post('/TReservationInfo/bulk-toggle');

        $this->assertResponseOk();
        $this->assertSame(1, $this->json()['data']['ok']);
        $this->assertSame(1, $this->eatFlag(3, 2));
    }

    public function testBulkToggle_emptyOrMissingItemsReturn400(): void
    {
        $this->loginAdmin();
        $this->post('/TReservationInfo/bulk-toggle', ['items' => []]);
        $this->assertResponseCode(400);
        $this->assertResponseContains('items is required.');

        $this->post('/TReservationInfo/bulk-toggle', ['foo' => 'bar']);
        $this->assertResponseCode(400);
    }

    public function testBulkToggle_tooManyItemsReturn400(): void
    {
        $this->loginAdmin();
        $this->post('/TReservationInfo/bulk-toggle', ['items' => array_fill(0, 5001, ['roomId' => 1])]);

        $this->assertResponseCode(400);
        $this->assertResponseContains('items too many.');
    }

    /** 児童は入口の認可は通るが、他人の予約は item 単位で拒否され、保存されない */
    public function testBulkToggle_childCannotChangeOtherUsersItems(): void
    {
        $this->loginChild();
        $this->post('/TReservationInfo/bulk-toggle', ['items' => [
            ['roomId' => 1, 'userId' => 2, 'date' => $this->farDate(), 'meal' => 1, 'value' => 1],
        ]]);

        $this->assertResponseOk();
        $data = $this->json()['data'];
        $this->assertSame(0, $data['ok']);
        $this->assertSame(1, $data['fail']);
        $this->assertNull($this->eatFlag(2, 1));
    }
}
