<?php
declare(strict_types=1);

namespace App\Test\TestCase\Infrastructure\Query;

use App\Application\Query\Dto\DayViewUser;
use App\Infrastructure\Query\CakeDayViewQuery;
use App\Infrastructure\Query\CakeRoomQuery;
use Cake\Chronos\ClockFactory;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\Date;
use Cake\TestSuite\TestCase;

/**
 * 日別の食数状況（CakeDayViewQuery）。旧 ReservationViewServiceTest を引き継ぐ。
 * フィクスチャ：部屋1に 1・2・3（6 は削除済み）。
 */
class CakeDayViewQueryTest extends TestCase
{
    protected array $fixtures = [
        'app.MRoomInfo',
        'app.MUserGroup',
        'app.MUserInfo',
        'app.TIndividualReservationInfo',
    ];

    private function query(): CakeDayViewQuery
    {
        return new CakeDayViewQuery(new CakeRoomQuery(), new ClockFactory('Asia/Tokyo'));
    }

    public function testNotLoggedIn_noRoomsAndToday(): void
    {
        foreach ([null, '', ':date'] as $date) {
            $day = $this->query()->getDayView(0, $date, null);

            $this->assertSame(Date::today('Asia/Tokyo')->format('Y-m-d'), $day->date);
            $this->assertSame([], $day->authorizedRooms);
            $this->assertSame(0, $day->activeRoomId);
            $this->assertSame('', $day->activeRoomName);
            $this->assertSame([], $day->users);
        }
    }

    public function testMemberSeesRoomUsersAndMeals(): void
    {
        $date = '2099-01-01';
        $db = ConnectionManager::get('test');
        $db->insert('m_room_info', ['i_id_room' => 2, 'c_room_name' => '別の部屋', 'i_disp_no' => 2, 'i_del_flg' => 0]);
        $insert = fn(int $userId, int $roomId, int $type, int $eat) => $db->insert('t_individual_reservation_info', [
            'i_id_user' => $userId, 'd_reservation_date' => $date, 'i_reservation_type' => $type, 'i_id_room' => $roomId,
            'eat_flag' => $eat, 'i_change_flag' => $eat, 'i_version' => 1, 'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't',
        ]);
        $insert(1, 1, 1, 1);
        $insert(2, 1, 2, 0);
        $insert(3, 2, 3, 1);
        $insert(6, 1, 1, 1);

        $day = $this->query()->getDayView(1, $date, 999);

        $this->assertSame(1, $day->activeRoomId, '選べない部屋を指定したら、選べる部屋の先頭');
        $this->assertNotSame('', $day->activeRoomName);
        $ids = array_map(static fn(DayViewUser $u) => $u->userId, $day->users);
        $this->assertContains(1, $ids);
        $this->assertNotContains(6, $ids, '削除済みの人は出ない');
        $this->assertSame([1 => [1 => true]], $day->userMeals, '食べない予約・別の部屋の予約・削除済みの人は入らない');
        $this->assertSame([3 => [3 => '別の部屋']], $day->otherRoomMeals);
    }
}
