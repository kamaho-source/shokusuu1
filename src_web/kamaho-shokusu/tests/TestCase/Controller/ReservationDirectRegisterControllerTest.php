<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Datasource\ConnectionManager;
use Cake\Http\Middleware\CsrfProtectionMiddleware;
use Cake\I18n\Date;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * ReservationDirectRegisterController（カレンダークリック直接登録・部屋変更）のテスト。
 */
class ReservationDirectRegisterControllerTest extends TestCase
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

    /** 児童 user 3（部屋1に所属）でログインする */
    private function loginChild(): void
    {
        $this->session(['Auth' => [
            'i_id_user' => 3, 'c_login_account' => 'non_staff_user', 'c_user_name' => 'テストユーザー',
            'i_admin' => 0, 'i_user_level' => 1, 'i_id_room' => 1,
        ]]);
        $this->enableCsrfToken();
        $this->configRequest(['headers' => ['Accept' => 'application/json']]);
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

    private function countReservations(int $userId, int $roomId): int
    {
        return $this->getTableLocator()->get('TIndividualReservationInfo')->find()
            ->where(['i_id_user' => $userId, 'i_id_room' => $roomId, 'd_reservation_date' => $this->farDate(), 'eat_flag' => 1])
            ->count();
    }

    public function testRegister_registersMultipleMealsForSelf(): void
    {
        $this->loginChild();
        $this->post('/TReservationInfo/direct-register', [
            'date' => $this->farDate(), 'roomId' => 1, 'meals' => [1, 3, 3],
        ]);

        $this->assertResponseOk();
        $body = $this->json();
        $this->assertTrue($body['ok']);
        $this->assertSame([1, 3], $body['data']['registered']);
        $this->assertSame([], $body['data']['skipped']);
        $this->assertSame(2, $this->countReservations(3, 1));
    }

    /** 同じ食事をもう一度登録しても、行は増えない */
    public function testRegister_sameMealTwiceDoesNotDuplicate(): void
    {
        $this->loginChild();
        $payload = ['date' => $this->farDate(), 'roomId' => 1, 'meals' => [1]];
        $this->post('/TReservationInfo/direct-register', $payload);
        $this->assertResponseOk();
        $this->post('/TReservationInfo/direct-register', $payload);
        $this->assertResponseOk();

        $this->assertSame(1, $this->countReservations(3, 1));
    }

    /** 昼食が登録済みの部屋に弁当を登録すると、昼食は取り消される（入れ替わり） */
    public function testRegister_bentoSwapsOutLunch(): void
    {
        $this->loginChild();
        $this->post('/TReservationInfo/direct-register', ['date' => $this->farDate(), 'roomId' => 1, 'meals' => [2]]);
        $this->assertResponseOk();

        $this->post('/TReservationInfo/direct-register', ['date' => $this->farDate(), 'roomId' => 1, 'meals' => [4]]);
        $this->assertResponseOk();
        $this->assertSame([4], $this->json()['data']['registered']);
        $flags = $this->getTableLocator()->get('TIndividualReservationInfo')->find()
            ->where(['i_id_user' => 3, 'd_reservation_date' => $this->farDate()])
            ->all()->combine('i_reservation_type', 'eat_flag')->toArray();
        $this->assertSame(0, (int)$flags[2]);
        $this->assertSame(1, (int)$flags[4]);
    }

    /** 別の部屋で同じ食事を予約済みなら、その食事だけスキップして残りは登録する */
    public function testRegister_mealAlreadyReservedInOtherRoomIsSkipped(): void
    {
        $conn = ConnectionManager::get('test');
        $conn->insert('m_room_info', [
            'i_id_room' => 2, 'c_room_name' => '部屋2', 'i_disp_no' => 2, 'i_enable' => 1, 'i_del_flg' => 0,
            'dt_create' => '2024-07-29 09:11:06', 'c_create_user' => 't',
        ]);
        $conn->insert('m_user_group', [
            'i_id_user' => 3, 'i_id_room' => 2, 'active_flag' => 0,
            'dt_create' => '2024-09-07 15:59:52', 'c_create_user' => 't',
        ]);
        $this->loginChild();
        $this->post('/TReservationInfo/direct-register', ['date' => $this->farDate(), 'roomId' => 1, 'meals' => [3]]);
        $this->assertResponseOk();

        $this->post('/TReservationInfo/direct-register', ['date' => $this->farDate(), 'roomId' => 2, 'meals' => [3, 1]]);
        $this->assertResponseOk();
        $body = $this->json();
        $this->assertSame([1], $body['data']['registered']);
        $this->assertSame([3], $body['data']['skipped']);
    }

    public function testRegister_acceptsJsonBody(): void
    {
        $token = (new CsrfProtectionMiddleware())->createToken();
        $this->session(['Auth' => [
            'i_id_user' => 3, 'c_login_account' => 'non_staff_user', 'c_user_name' => 'テストユーザー',
            'i_admin' => 0, 'i_user_level' => 1, 'i_id_room' => 1,
        ]]);
        $this->cookie('csrfToken', $token);
        $this->configRequest([
            'headers' => ['Accept' => 'application/json', 'X-CSRF-Token' => $token],
            'input'   => json_encode(['date' => $this->farDate(), 'roomId' => 1, 'meals' => [2]]),
        ]);
        $this->post('/TReservationInfo/direct-register');

        $this->assertResponseOk();
        $this->assertSame([2], $this->json()['data']['registered']);
    }

    public function testRegister_missingFieldsReturn400(): void
    {
        $this->loginChild();
        $cases = [
            'roomId'  => [['date' => $this->farDate(), 'meals' => [1]], 'roomId is required.'],
            'date'    => [['roomId' => 1, 'meals' => [1]], 'date is required.'],
            'meals'   => [['date' => $this->farDate(), 'roomId' => 1], 'meals is required.'],
        ];
        foreach ($cases as $name => [$data, $message]) {
            $this->post('/TReservationInfo/direct-register', $data);
            $this->assertResponseCode(400, $name);
            $this->assertResponseContains($message);
        }
    }

    public function testRegister_roomNotBelongingToUserIsForbidden(): void
    {
        ConnectionManager::get('test')->insert('m_room_info', [
            'i_id_room' => 2, 'c_room_name' => '部屋2', 'i_disp_no' => 2, 'i_enable' => 1, 'i_del_flg' => 0,
            'dt_create' => '2024-07-29 09:11:06', 'c_create_user' => 't',
        ]);
        $this->loginChild();
        $this->post('/TReservationInfo/direct-register', ['date' => $this->farDate(), 'roomId' => 2, 'meals' => [1]]);

        $this->assertResponseCode(403);
        $this->assertSame(0, $this->countReservations(3, 2));
    }

    public function testRegister_childCannotRegisterForOtherUser(): void
    {
        $this->loginChild();
        $this->post('/TReservationInfo/direct-register', [
            'date' => $this->farDate(), 'roomId' => 1, 'meals' => [1], 'userId' => 2,
        ]);

        $this->assertResponseCode(403);
        $this->assertSame(0, $this->countReservations(2, 1));
    }

    public function testRegister_invalidDateIsRejectedWithoutRegistering(): void
    {
        $this->loginChild();
        $this->post('/TReservationInfo/direct-register', ['date' => '2027-02-30', 'roomId' => 1, 'meals' => [1]]);

        $this->assertTrue($this->_response->getStatusCode() >= 400);
        $this->assertFalse($this->json()['ok']);
        $this->assertSame(0, $this->getTableLocator()->get('TIndividualReservationInfo')->find()->where(['i_id_user' => 3])->count());
    }

    public function testChangeRoom_missingFieldsReturn400(): void
    {
        $this->loginChild();
        $this->post('/TReservationInfo/change-room', ['date' => $this->farDate(), 'meal' => 3, 'fromRoomId' => 1]);

        $this->assertResponseCode(400);
        $this->assertResponseContains('変更前と変更後の部屋を指定してください');
    }

    public function testChangeRoom_movesReservationToOtherRoom(): void
    {
        $conn = ConnectionManager::get('test');
        $conn->insert('m_room_info', [
            'i_id_room' => 2, 'c_room_name' => '部屋2', 'i_disp_no' => 2, 'i_enable' => 1, 'i_del_flg' => 0,
            'dt_create' => '2024-07-29 09:11:06', 'c_create_user' => 't',
        ]);
        $conn->insert('m_user_group', [
            'i_id_user' => 3, 'i_id_room' => 2, 'active_flag' => 0,
            'dt_create' => '2024-09-07 15:59:52', 'c_create_user' => 't',
        ]);
        $this->loginChild();
        $this->post('/TReservationInfo/direct-register', ['date' => $this->farDate(), 'roomId' => 1, 'meals' => [3]]);
        $this->assertResponseOk();

        $this->post('/TReservationInfo/change-room', [
            'date' => $this->farDate(), 'meal' => 3, 'fromRoomId' => 1, 'toRoomId' => 2,
        ]);

        $this->assertResponseOk();
        $body = $this->json();
        $this->assertSame(['meal' => 3, 'fromRoomId' => 1, 'toRoomId' => 2], $body['data']);
        $this->assertSame(0, $this->countReservations(3, 1));
        $this->assertSame(1, $this->countReservations(3, 2));
    }

    public function testChangeRoom_toRoomWithoutMembershipIsForbidden(): void
    {
        ConnectionManager::get('test')->insert('m_room_info', [
            'i_id_room' => 2, 'c_room_name' => '部屋2', 'i_disp_no' => 2, 'i_enable' => 1, 'i_del_flg' => 0,
            'dt_create' => '2024-07-29 09:11:06', 'c_create_user' => 't',
        ]);
        $this->loginChild();
        $this->post('/TReservationInfo/direct-register', ['date' => $this->farDate(), 'roomId' => 1, 'meals' => [3]]);

        $this->post('/TReservationInfo/change-room', [
            'date' => $this->farDate(), 'meal' => 3, 'fromRoomId' => 1, 'toRoomId' => 2,
        ]);

        $this->assertResponseCode(403);
        $this->assertSame(1, $this->countReservations(3, 1));
    }
}
