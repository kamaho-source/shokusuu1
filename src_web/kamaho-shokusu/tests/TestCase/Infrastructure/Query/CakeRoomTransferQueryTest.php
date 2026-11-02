<?php
declare(strict_types=1);

namespace App\Test\TestCase\Infrastructure\Query;

use App\Application\Query\Dto\RoomTransferView;
use App\Infrastructure\Query\CakeRoomTransferQuery;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * 部屋異動の画面の読み取り（CakeRoomTransferQuery）。
 */
class CakeRoomTransferQueryTest extends TestCase
{
    protected array $fixtures = ['app.MUserInfo', 'app.MUserGroup', 'app.MRoomInfo', 'app.MRoomTransferSchedule'];

    private function insert(int $userId, ?int $from, int $to, string $date, int $status): void
    {
        ConnectionManager::get('test')->insert('m_room_transfer_schedule', [
            'i_id_user' => $userId, 'i_id_room_from' => $from, 'i_id_room_to' => $to, 'd_effective_date' => $date,
            'i_status' => $status, 'c_create_user' => '管理者', 'dt_create' => '2026-10-01 09:30:00',
        ]);
    }

    public function testSchedules_newestFirstWithNamesAndStatusFilter(): void
    {
        ConnectionManager::get('test')->execute('DELETE FROM m_room_transfer_schedule');
        ConnectionManager::get('test')->insert('m_room_info', ['i_id_room' => 2, 'c_room_name' => '部屋2', 'i_disp_no' => 2, 'i_enable' => 1, 'i_del_flg' => 0]);
        $this->insert(3, 1, 2, '2026-11-01', 0);
        $this->insert(2, null, 1, '2026-12-01', 1);

        $all = $this->query()->schedules(null);
        $this->assertSame(['2026-12-01', '2026-11-01'], array_map(static fn(RoomTransferView $v) => $v->effectiveDate, $all));
        $this->assertSame(['職員ユーザー', null, 'Lorem ipsum dolor sit amet', 1, '管理者', '2026-10-01 09:30:00'], [$all[0]->userName, $all[0]->roomFromName, $all[0]->roomToName, $all[0]->status, $all[0]->createdBy, $all[0]->createdAt]);
        $this->assertSame(['一般ユーザー', 'Lorem ipsum dolor sit amet', '部屋2'], [$all[1]->userName, $all[1]->roomFromName, $all[1]->roomToName]);
        $this->assertSame(['2026-11-01'], array_map(static fn(RoomTransferView $v) => $v->effectiveDate, $this->query()->schedules(0)));
    }

    public function testFormOptions(): void
    {
        $users = $this->query()->selectableUsers();
        $this->assertArrayNotHasKey(6, $users, '削除済みは出ない');
        $this->assertSame([1, 2, 3], array_slice(array_keys($users), 0, 3), '表示順');
        $this->assertSame([1 => 'Lorem ipsum dolor sit amet'], $this->query()->selectableRooms());

        ConnectionManager::get('test')->update('m_user_group', ['active_flag' => 1], ['i_id_user' => 2]);
        $map = $this->query()->activeRoomsByUser();
        $this->assertSame([1], $map[1]);
        $this->assertArrayNotHasKey(2, $map, '抜けた所属は出ない');
    }

    private function query(): CakeRoomTransferQuery
    {
        return new CakeRoomTransferQuery();
    }
}
