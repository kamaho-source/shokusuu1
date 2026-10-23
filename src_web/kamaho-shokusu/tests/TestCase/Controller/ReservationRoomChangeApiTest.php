<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Datasource\ConnectionManager;
use Cake\I18n\Date;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * 予約の部屋変更（change-room）と、退出済み・削除済みの部屋に残った予約の取消（toggle）の API テスト。
 */
class ReservationRoomChangeApiTest extends TestCase
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

    /** 児童 user 3 でログインする（部屋1に所属） */
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

    /** 部屋2（$deleted なら削除済み）に、user 3 の退出済みの所属と、有効な夕食の予約を作る */
    private function leftoverReservationInRoom2(bool $deleted): void
    {
        $conn = ConnectionManager::get('test');
        $conn->insert('m_room_info', [
            'i_id_room' => 2, 'c_room_name' => '部屋2', 'i_disp_no' => 2, 'i_enable' => 1, 'i_del_flg' => $deleted ? 1 : 0,
            'dt_create' => '2024-07-29 09:11:06', 'c_create_user' => 't',
        ]);
        $conn->insert('m_user_group', [
            'i_id_user' => 3, 'i_id_room' => 2, 'active_flag' => 1,
            'dt_create' => '2024-09-07 15:59:52', 'c_create_user' => 't',
        ]);
        $conn->insert('t_individual_reservation_info', [
            'i_id_user' => 3, 'd_reservation_date' => $this->farDate(), 'i_reservation_type' => 3, 'i_id_room' => 2,
            'eat_flag' => 1, 'i_change_flag' => 1, 'i_version' => 1,
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't',
        ]);
    }

    private function dinnerFlagsInRoom2(): array
    {
        $row = $this->getTableLocator()->get('TIndividualReservationInfo')->find()
            ->where(['i_id_user' => 3, 'i_id_room' => 2, 'd_reservation_date' => $this->farDate(), 'i_reservation_type' => 3])
            ->firstOrFail();

        return [(int)$row->eat_flag, (int)$row->i_change_flag];
    }

    public function testChangeRoom_missingFields_returns400WithReason(): void
    {
        $this->loginChild();
        $this->post('/TReservationInfo/change-room', ['date' => $this->farDate(), 'fromRoomId' => 1, 'toRoomId' => 2]);

        $this->assertResponseCode(400);
        $this->assertResponseContains('部屋を指定してください');
    }

    public function testChangeRoom_nonexistentDate_returns400WithReason(): void
    {
        // 両方の部屋の権限を通すため、部屋2にも有効に所属させる
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
        $this->post('/TReservationInfo/change-room', ['date' => '2027-02-30', 'meal' => 3, 'fromRoomId' => 1, 'toRoomId' => 2, 'userId' => 3]);

        $this->assertResponseCode(400);
        $this->assertResponseContains('日付が正しくありません');
    }

    /** 退出済みの部屋に残った自分の予約は、取り消せる */
    public function testToggle_selfCanCancelLeftoverInExitedRoom(): void
    {
        $this->leftoverReservationInRoom2(false);
        $this->loginChild();
        $this->post('/TReservationInfo/toggle/2', ['date' => $this->farDate(), 'meal' => 3, 'value' => 0, 'userId' => 3]);

        $this->assertResponseOk();
        $this->assertSame([0, 0], $this->dinnerFlagsInRoom2());
    }

    /** 削除済みの部屋に残った自分の予約も、取り消せる */
    public function testToggle_selfCanCancelLeftoverInDeletedRoom(): void
    {
        $this->leftoverReservationInRoom2(true);
        $this->loginChild();
        $this->post('/TReservationInfo/toggle/2', ['date' => $this->farDate(), 'meal' => 3, 'value' => 0, 'userId' => 3]);

        $this->assertResponseOk();
        $this->assertSame([0, 0], $this->dinnerFlagsInRoom2());
    }

    /** 退出済みの部屋に、新しく登録することはできない（取消だけを許す） */
    public function testToggle_selfCannotRegisterInExitedRoom(): void
    {
        $this->leftoverReservationInRoom2(false);
        $this->loginChild();
        $this->post('/TReservationInfo/toggle/2', ['date' => $this->farDate(), 'meal' => 1, 'value' => 1, 'userId' => 3]);

        $this->assertResponseCode(403);
        $this->assertFalse($this->getTableLocator()->get('TIndividualReservationInfo')->exists([
            'i_id_user' => 3, 'i_id_room' => 2, 'i_reservation_type' => 1,
        ]));
    }
}
