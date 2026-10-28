<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\SystemReportService;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\Date;
use Cake\TestSuite\TestCase;

/**
 * SystemReportService のテスト。
 */
class SystemReportServiceTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TIndividualReservationInfo',
        'app.TAuditLog',
    ];

    /** 日別の集計は、予約日を Y-m-d で数える（文字列にした日付がロケール依存の形になり、0件になっていた） */
    public function testGetDailyStats_countsReservationsByDate(): void
    {
        $date = Date::today()->subDays(3)->format('Y-m-d');
        ConnectionManager::get('test')->insert('t_individual_reservation_info', [
            'i_id_user' => 3, 'd_reservation_date' => $date, 'i_reservation_type' => 1, 'i_id_room' => 1,
            'eat_flag' => 0, 'i_change_flag' => 1, 'i_version' => 1,
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't',
        ]);

        $stats = (new SystemReportService())->getDailyStats([], $date, $date);

        $this->assertSame(1, $stats[0]['child_count'], '児童（user 3）の直前に追加した予約が数えられていない');
    }

    private function insertReservation(int $userId, int $roomId, string $date, int $eat, ?int $change): void
    {
        ConnectionManager::get('test')->insert('t_individual_reservation_info', [
            'i_id_user' => $userId, 'd_reservation_date' => $date, 'i_reservation_type' => 1, 'i_id_room' => $roomId,
            'eat_flag' => $eat, 'i_change_flag' => $change, 'i_version' => 1,
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't',
        ]);
    }

    /** 部屋別: 児童(user3)・大人(user1,2)を分けて数え、削除済み(user6)は人数に入れない。使用率は 予約数/(人数×日数×4)。 */
    public function testGetRoomStats_countsChildAndAdultSeparately(): void
    {
        $this->insertReservation(3, 1, '2026-03-01', 0, 1);
        $this->insertReservation(2, 1, '2026-03-01', 0, 1);
        // 変更フラグが 0 なら eat_flag が 1 でも数えない
        $this->insertReservation(3, 1, '2026-03-02', 1, 0);
        // 範囲外
        $this->insertReservation(3, 1, '2026-04-01', 0, 1);
        // 別の部屋に所属していないユーザーの予約は無視
        $this->insertReservation(2, 99, '2026-03-01', 0, 1);

        $stats = (new SystemReportService())->getRoomStats([], '2026-03-01', '2026-03-02');

        $this->assertCount(1, $stats);
        $this->assertSame(1, $stats[0]['room_id']);
        $this->assertSame(1, $stats[0]['child_users']);
        $this->assertSame(2, $stats[0]['adult_users']);
        $this->assertSame(1, $stats[0]['child_reservations']);
        $this->assertSame(1, $stats[0]['adult_reservations']);
        $this->assertSame(12.5, $stats[0]['child_usage_rate']);
        $this->assertSame(6.3, $stats[0]['adult_usage_rate']);
    }

    public function testGetRoomStats_excludedUserIsNotCounted(): void
    {
        $this->insertReservation(1, 1, '2026-03-01', 0, 1);
        $this->insertReservation(2, 1, '2026-03-01', 0, 1);

        $stats = (new SystemReportService())->getRoomStats([1], '2026-03-01', '2026-03-01');

        $this->assertSame(1, $stats[0]['adult_users']);
        $this->assertSame(1, $stats[0]['adult_reservations']);
        // 1人 × 1日 × 4食 = 4 のうち 1 → 25%
        $this->assertSame(25.0, $stats[0]['adult_usage_rate']);
    }

    public function testGetRoomStats_roomsSortedByNameAndEmptyWhenEveryoneExcluded(): void
    {
        $conn = ConnectionManager::get('test');
        $conn->insert('m_room_info', [
            'i_id_room' => 2, 'c_room_name' => 'Alpha', 'i_disp_no' => 2, 'i_enable' => 1, 'i_del_flg' => 0,
            'dt_create' => '2024-07-29 09:11:06', 'c_create_user' => 't', 'dt_update' => '2024-07-29 09:11:06', 'c_update_user' => 't',
        ]);
        $conn->insert('m_user_group', [
            'i_id_user' => 4, 'i_id_room' => 2, 'active_flag' => 0,
            'dt_create' => '2024-09-07 15:59:52', 'c_create_user' => 't', 'dt_update' => '2024-09-07 15:59:52', 'c_update_user' => 't',
        ]);
        $service = new SystemReportService();

        $stats = $service->getRoomStats([], '2026-03-01', '2026-03-01');
        $this->assertSame([2, 1], array_column($stats, 'room_id'));
        // 予約がなくても使用率は 0.0（0 除算しない）
        $this->assertSame(0.0, $stats[0]['child_usage_rate']);

        $this->assertSame([], $service->getRoomStats([1, 2, 3, 4], '2026-03-01', '2026-03-01'));
    }

    public function testGetRoomStats_inactiveMembershipIsIgnored(): void
    {
        ConnectionManager::get('test')->update('m_user_group', ['active_flag' => 1], ['i_id_user' => 3]);

        $stats = (new SystemReportService())->getRoomStats([], '2026-03-01', '2026-03-01');

        $this->assertSame(0, $stats[0]['child_users']);
    }

    public function testGetDailyStats_splitsChildAdultAndHonoursExclusion(): void
    {
        $this->insertReservation(3, 1, '2026-03-01', 0, 1);
        $this->insertReservation(2, 1, '2026-03-01', 0, 1);
        $this->insertReservation(1, 1, '2026-03-02', 0, 1);
        // 削除済みユーザー(6)は対象外
        $this->insertReservation(6, 1, '2026-03-01', 0, 1);

        $service = new SystemReportService();
        $stats = $service->getDailyStats([], '2026-03-01', '2026-03-03');

        $this->assertSame(['2026-03-01', '2026-03-02', '2026-03-03'], array_column($stats, 'date'));
        $this->assertSame([1, 0, 0], array_column($stats, 'child_count'));
        $this->assertSame([1, 1, 0], array_column($stats, 'adult_count'));
        $this->assertSame([2, 1, 0], array_column($stats, 'total'));

        $excluded = $service->getDailyStats([2, 3], '2026-03-01', '2026-03-03');
        $this->assertSame([0, 1, 0], array_column($excluded, 'total'));
    }

    public function testGetDailyChildrenStats_returnsOnlyChildCount(): void
    {
        $this->insertReservation(3, 1, '2026-03-01', 0, 1);
        $this->insertReservation(2, 1, '2026-03-01', 0, 1);

        $stats = (new SystemReportService())->getDailyChildrenStats([], '2026-03-01', '2026-03-01');

        $this->assertSame([['date' => '2026-03-01', 'child_count' => 1]], $stats);
    }

    public function testGetLoginStats_bucketsByDayAndResolvesFailedLoginName(): void
    {
        $conn = ConnectionManager::get('test');
        // 失敗ログ: 操作者不明・入力されたログインIDが実在アカウント → アカウント名で表示
        $conn->insert('t_audit_log', [
            'c_category' => 'user', 'c_action' => 'user_login_failed', 'i_actor_user_id' => null,
            'c_actor_user_name' => 'staff_user', 'c_actor_login_id' => 'staff_user',
            'c_ip_address' => '10.0.0.9', 'i_result' => 0, 'dt_create' => '2026-06-02 09:00:00',
        ]);
        // 範囲外・対象外アクション
        $conn->insert('t_audit_log', [
            'c_category' => 'user', 'c_action' => 'user_login', 'i_actor_user_id' => 1,
            'c_actor_user_name' => 'x', 'i_result' => 1, 'dt_create' => '2026-07-01 09:00:00',
        ]);

        $stats = (new SystemReportService())->getLoginStats('2026-06-01', '2026-06-02');

        $this->assertSame(
            [['date' => '2026-06-01', 'success' => 1, 'failed' => 1], ['date' => '2026-06-02', 'success' => 0, 'failed' => 1]],
            $stats['daily']
        );
        $this->assertCount(3, $stats['logs']);
        // 新しい順
        $this->assertSame('2026-06-02 09:00:00', $stats['logs'][0]['dt']);
        $this->assertSame('職員ユーザー', $stats['logs'][0]['user_name']);
        $this->assertSame('staff_user', $stats['logs'][0]['login_id']);
        $this->assertSame('10.0.0.9', $stats['logs'][0]['ip']);
        $this->assertSame(0, $stats['logs'][0]['result']);
        $this->assertSame(1, $stats['logs'][2]['result']);
    }

    public function testGetAllUsers_adultsFirstThenChildren_excludingDeleted(): void
    {
        $users = (new SystemReportService())->getAllUsers();

        $ids = array_column($users, 'user_id');
        $this->assertNotContains(6, $ids);
        $this->assertSame(3, end($users)['user_id']);
        $this->assertTrue(end($users)['is_child']);
        $isChild = array_column($users, 'is_child');
        $this->assertSame($isChild, array_values(array_merge(array_filter($isChild, fn($c) => !$c), array_filter($isChild, fn($c) => $c))));
    }
}
