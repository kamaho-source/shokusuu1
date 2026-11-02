<?php
declare(strict_types=1);

namespace App\Test\TestCase\Infrastructure\Query;

use App\Application\Query\Dto\RoomUsage;
use App\Infrastructure\Query\CakeRoomUsageQuery;
use Cake\Chronos\ClockFactory;
use Cake\Datasource\ConnectionManager;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * 部屋使用率の読み取り（CakeRoomUsageQuery）。旧 RoomUsageServiceTest を引き継ぎ、値まで確かめる。
 *
 * フィクスチャの部屋1: 管理者1（職員）・職員2・児童3・削除済み6。
 */
class CakeRoomUsageQueryTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TIndividualReservationInfo',
    ];

    private CakeRoomUsageQuery $query;

    protected function setUp(): void
    {
        parent::setUp();
        $this->query = new CakeRoomUsageQuery(new ClockFactory('Asia/Tokyo'));
        TableRegistry::getTableLocator()->get('TIndividualReservationInfo')->deleteAll([]);
    }

    private function insert(int $userId, int $roomId, string $date, int $type, int $eat, ?int $change): void
    {
        ConnectionManager::get('test')->insert('t_individual_reservation_info', [
            'i_id_user' => $userId, 'd_reservation_date' => $date, 'i_reservation_type' => $type, 'i_id_room' => $roomId,
            'eat_flag' => $eat, 'i_change_flag' => $change, 'i_version' => 1,
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't',
        ]);
    }

    private function seed(): void
    {
        $this->insert(3, 1, '2026-03-01', 1, 0, 1);   // 児童・食べる
        $this->insert(2, 1, '2026-03-01', 1, 1, 0);   // 職員・直前で取消 → 数えない
        $this->insert(1, 1, '2026-03-02', 2, 0, 1);   // 管理者（職員）・食べる
        $this->insert(6, 1, '2026-03-01', 1, 0, 1);   // 削除済み → 数えない
        $this->insert(2, 99, '2026-03-01', 1, 0, 1);  // 在籍していない部屋 → 数えない
        $this->insert(3, 1, '2026-03-03', 1, 0, 1);   // 期間外
    }

    /** @return array<string, mixed> */
    private function room(array $usage, int $roomId): array
    {
        foreach ($usage as $r) {
            if ($r->roomId === $roomId) {
                return $r->toArray();
            }
        }
        $this->fail('room ' . $roomId . ' not found');
    }

    /** 使用率 = 食べる件数 ÷（在籍者数 × 日数 × 4）。削除済みの人は在籍者数にも件数にも入れない。職員の個別の使用率は名前順 */
    public function testRoomUsage_countsActiveMembersAndStaff(): void
    {
        $this->seed();

        $this->assertSame([
            'room_id' => 1, 'room_name' => 'Lorem ipsum dolor sit amet', 'user_count' => 3, 'capacity' => 24, 'eat_count' => 2, 'usage_rate' => 8.3,
            'staff' => [
                ['user_id' => 1, 'user_name' => '管理者ユーザー', 'capacity' => 8, 'eat_count' => 1, 'usage_rate' => 12.5],
                ['user_id' => 2, 'user_name' => '職員ユーザー', 'capacity' => 8, 'eat_count' => 0, 'usage_rate' => 0.0],
            ],
        ], $this->room($this->query->roomUsage('2026-03-01', '2026-03-02'), 1));
    }

    /** 食事を1つに絞ると、分母の食事の数は 1 */
    public function testRoomUsage_mealTypeFilter(): void
    {
        $this->seed();

        $room = $this->room($this->query->roomUsage('2026-03-01', '2026-03-02', 1), 1);

        $this->assertSame([6, 1, 16.7], [$room['capacity'], $room['eat_count'], $room['usage_rate']]);
        $this->assertSame([2, 0, 0.0], [$room['staff'][0]['capacity'], $room['staff'][0]['eat_count'], $room['staff'][0]['usage_rate']]);
    }

    /** 区分で絞ると、その区分の人だけを分母・分子に入れる */
    public function testRoomUsage_levelFilter(): void
    {
        $this->seed();

        $room = $this->room($this->query->roomUsage('2026-03-01', '2026-03-02', null, 1), 1);

        $this->assertSame([1, 8, 1, 12.5, []], [$room['user_count'], $room['capacity'], $room['eat_count'], $room['usage_rate'], $room['staff']]);
    }

    /** 職員レベル 7 も、職員(0)と同じく職員別の使用率に載る */
    public function testRoomUsage_level7StaffIsListed(): void
    {
        TableRegistry::getTableLocator()->get('MUserInfo')->updateAll(['i_user_level' => 7], ['i_id_user' => 2]);

        $staff = $this->room($this->query->roomUsage('2026-03-01', '2026-03-01'), 1)['staff'];

        $this->assertSame([1, 2], array_column($staff, 'user_id'));
    }

    /** 職員の個別の使用率は名前の順（ID の順ではない） */
    public function testRoomUsage_staffSortedByName(): void
    {
        TableRegistry::getTableLocator()->get('MUserInfo')->updateAll(['c_user_name' => '龍職員'], ['i_id_user' => 1]);

        $staff = $this->room($this->query->roomUsage('2026-03-01', '2026-03-01'), 1)['staff'];

        $this->assertSame([2, 1], array_column($staff, 'user_id'));
    }

    /** 退出済みの所属・削除済みの部屋は数えない（削除済みの部屋は以前は出ていた） */
    public function testRoomUsage_skipsLeftMembershipsAndDeletedRooms(): void
    {
        $conn = ConnectionManager::get('test');
        $conn->update('m_user_group', ['active_flag' => 1], ['i_id_user' => 3]);
        $this->assertSame(2, $this->room($this->query->roomUsage('2026-03-01', '2026-03-01'), 1)['user_count']);

        $conn->update('m_room_info', ['i_del_flg' => 1], ['i_id_room' => 1]);
        $this->assertSame([], $this->query->roomUsage('2026-03-01', '2026-03-01'));
    }

    /** 部屋名の順に並べる。在籍者がいなければ空 */
    public function testRoomUsage_sortedByRoomName(): void
    {
        $conn = ConnectionManager::get('test');
        $conn->insert('m_room_info', ['i_id_room' => 2, 'c_room_name' => 'Alpha', 'i_disp_no' => 2, 'i_enable' => 1, 'i_del_flg' => 0]);
        $conn->insert('m_user_group', ['i_id_user' => 4, 'i_id_room' => 2, 'active_flag' => 0]);

        $this->assertSame([2, 1], array_map(static fn(RoomUsage $r) => $r->roomId, $this->query->roomUsage('2026-03-01', '2026-03-01')));

        $conn->update('m_user_group', ['active_flag' => 1], []);
        $this->assertSame([], $this->query->roomUsage('2026-03-01', '2026-03-01'));
    }
}
