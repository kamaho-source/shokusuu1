<?php
declare(strict_types=1);

namespace App\Test\TestCase\Infrastructure\Query;

use App\Application\Query\Dto\MealGridUser;
use App\Infrastructure\Query\CakeMealCountGridQuery;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;
use Psr\Clock\ClockInterface;

/**
 * エクセル食数予約の読み取り（CakeMealCountGridQuery）。旧 MealCountGridServiceTest の DB の部分を引き継ぐ。
 *
 * 今日は 2026-05-13 に固定する（05-27 までが直前期間で i_change_flag を見る。05-28 以降は eat_flag）。
 */
class CakeMealCountGridQueryTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserGroup',
        'app.MUserInfo',
        'app.TIndividualReservationInfo',
    ];

    private CakeMealCountGridQuery $query;

    protected function setUp(): void
    {
        parent::setUp();
        $this->query = new CakeMealCountGridQuery(new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-05-13 09:00:00', new \DateTimeZone('Asia/Tokyo'));
            }
        });
    }

    private function insert(int $userId, int $roomId, string $date, int $type, int $eat, ?int $change): void
    {
        ConnectionManager::get('test')->insert('t_individual_reservation_info', [
            'i_id_user' => $userId, 'i_id_room' => $roomId, 'd_reservation_date' => $date, 'i_reservation_type' => $type,
            'eat_flag' => $eat, 'i_change_flag' => $change, 'i_version' => 1,
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't',
        ]);
    }

    /** @return array<int, list<MealGridUser>> */
    private function users(int ...$ids): array
    {
        return [1 => array_map(static fn(int $id) => new MealGridUser($id, 'u' . $id, 0), $ids)];
    }

    // ---- getRoomUsers ----

    /** 所属中で削除されていない利用者を、表示順で返す（削除済みの 6 は出ない） */
    public function testGetRoomUsers_returnsActiveUsersInDisplayOrder(): void
    {
        $users = $this->query->getRoomUsers([1])[1];

        $this->assertSame([1, 2, 3], array_map(static fn(MealGridUser $u) => $u->id, $users));
        $this->assertSame(['管理者ユーザー', 0], [$users[0]->name, $users[0]->level]);
        $this->assertSame(1, $users[2]->level);
    }

    public function testGetRoomUsers_excludesWithdrawnMembers(): void
    {
        ConnectionManager::get('test')->update('m_user_group', ['active_flag' => 1], ['i_id_user' => 2]);

        $this->assertSame([1, 3], array_map(static fn(MealGridUser $u) => $u->id, $this->query->getRoomUsers([1])[1]));
    }

    public function testGetRoomUsers_unknownRoomHasEmptyList(): void
    {
        $this->assertEquals([9999 => [], 1 => $this->query->getRoomUsers([1])[1]], $this->query->getRoomUsers(['9999', 1, 1]));
        $this->assertSame([], $this->query->getRoomUsers([]));
    }

    // ---- getGrid ----

    public function testGetGrid_noRooms_hasDatesMealsAndZeroTotals(): void
    {
        $grid = $this->query->getGrid([], [], ['2026-05-30', '2026-05-31']);

        $this->assertSame(['2026-05-30', '2026-05-31'], $grid->dates);
        $this->assertSame([1 => '朝', 2 => '昼', 3 => '夕', 4 => '弁'], $grid->meals);
        $this->assertSame([], $grid->rooms);
        $this->assertSame(array_fill_keys(['2026-05-30', '2026-05-31'], [1 => 0, 2 => 0, 3 => 0, 4 => 0]), $grid->dailyTotals);
        $this->assertSame([1 => 0, 2 => 0, 3 => 0, 4 => 0], $grid->totalsByMeal());
    }

    /** 直前期間は i_change_flag（NULL なら eat_flag）、それより先は eat_flag で「食べる」を決め、日ごとに数える */
    public function testGetGrid_marksEatenMealsByCountedFlag(): void
    {
        $this->insert(1, 1, '2026-05-20', 1, 0, 1);    // 直前・変更で食べる
        $this->insert(2, 1, '2026-05-20', 1, 1, 0);    // 直前・変更で食べない
        $this->insert(3, 1, '2026-05-20', 3, 1, null); // 直前・変更なし → eat_flag
        $this->insert(1, 1, '2026-05-30', 2, 0, 1);    // 先・eat_flag だけを見る → 食べない
        $this->insert(2, 1, '2026-05-30', 4, 1, 0);    // 先・eat_flag=1 → 食べる

        $grid = $this->query->getGrid([1 => '部屋A'], $this->users(1, 2, 3), ['2026-05-20', '2026-05-30']);
        $room = $grid->rooms[1];

        $this->assertSame(['部屋A', 1], [$room->name, $room->id]);
        $this->assertSame([1, 2, 3], array_map(static fn(MealGridUser $u) => $u->id, $room->users));
        $this->assertSame([1 => true, 2 => false, 3 => false, 4 => false], $room->grid[1]['2026-05-20']);
        $this->assertFalse($room->grid[2]['2026-05-20'][1]);
        $this->assertTrue($room->grid[3]['2026-05-20'][3]);
        $this->assertFalse($room->grid[1]['2026-05-30'][2]);
        $this->assertTrue($room->grid[2]['2026-05-30'][4]);
        $this->assertSame(['2026-05-20' => [1 => 1, 2 => 0, 3 => 1, 4 => 0], '2026-05-30' => [1 => 0, 2 => 0, 3 => 0, 4 => 1]], $grid->dailyTotals);
        $this->assertSame([1 => 1, 2 => 0, 3 => 1, 4 => 1], $grid->totalsByMeal());
    }

    /** 別の部屋で食べる予約があれば、この部屋のセルにその部屋を入れる（この部屋の合計には数えない） */
    public function testGetGrid_marksMealsReservedInOtherRoom(): void
    {
        $this->insert(1, 2, '2026-05-30', 1, 1, null); // 部屋2で食べる
        $this->insert(1, 2, '2026-05-30', 2, 0, null); // 部屋2で食べない → ロックしない
        $this->insert(2, 1, '2026-05-30', 1, 1, null); // この部屋で食べる → ロックしない

        $grid = $this->query->getGrid([1 => '部屋A'], $this->users(1, 2), ['2026-05-30']);
        $room = $grid->rooms[1];

        $this->assertSame([1 => 2], $room->otherRoom[1]['2026-05-30']);
        $this->assertSame([], $room->otherRoom[2]['2026-05-30']);
        $this->assertFalse($room->grid[1]['2026-05-30'][1]);
        $this->assertSame([1 => 1, 2 => 0, 3 => 0, 4 => 0], $grid->dailyTotals['2026-05-30']);
    }

    /** 部屋ごとに、その部屋の予約だけを行に出す（同じ人が2部屋にいても混ざらない） */
    public function testGetGrid_keepsRoomsSeparate(): void
    {
        $this->insert(1, 1, '2026-05-30', 1, 1, null);
        $this->insert(1, 2, '2026-05-30', 3, 1, null);

        $grid = $this->query->getGrid([1 => 'A', 2 => 'B'], [1 => [new MealGridUser(1, 'u1', 0)], 2 => [new MealGridUser(1, 'u1', 0)]], ['2026-05-30']);

        $this->assertSame([1 => true, 2 => false, 3 => false, 4 => false], $grid->rooms[1]->grid[1]['2026-05-30']);
        $this->assertSame([1 => false, 2 => false, 3 => true, 4 => false], $grid->rooms[2]->grid[1]['2026-05-30']);
        $this->assertSame([3 => 2], $grid->rooms[1]->otherRoom[1]['2026-05-30']);
        $this->assertSame([1 => 1], $grid->rooms[2]->otherRoom[1]['2026-05-30']);
        $this->assertSame([1 => 1, 2 => 0, 3 => 1, 4 => 0], $grid->dailyTotals['2026-05-30']);
    }

    /** 2部屋で同じ食事を食べる予約がある（二重予約）とき、どちらの部屋でも食べる扱いにし、選べなくはしない */
    public function testGetGrid_doubleBookingIsNotLockedInEitherRoom(): void
    {
        $this->insert(1, 2, '2026-05-30', 1, 1, null);
        $this->insert(1, 1, '2026-05-30', 1, 1, null);

        $grid = $this->query->getGrid([1 => 'A', 2 => 'B'], [1 => [new MealGridUser(1, 'u1', 0)], 2 => [new MealGridUser(1, 'u1', 0)]], ['2026-05-30']);

        $this->assertTrue($grid->rooms[1]->grid[1]['2026-05-30'][1]);
        $this->assertTrue($grid->rooms[2]->grid[1]['2026-05-30'][1]);
        $this->assertSame([], $grid->rooms[1]->otherRoom[1]['2026-05-30']);
        $this->assertSame([], $grid->rooms[2]->otherRoom[1]['2026-05-30']);
        $this->assertSame(2, $grid->dailyTotals['2026-05-30'][1]);
    }

    /** 行に出ない人・期間外の日の予約は読まない */
    public function testGetGrid_ignoresOtherUsersAndDates(): void
    {
        $this->insert(4, 1, '2026-05-30', 1, 1, null);
        $this->insert(1, 1, '2026-05-31', 1, 1, null);

        $grid = $this->query->getGrid([1 => 'A'], $this->users(1), ['2026-05-30']);

        $this->assertSame([1 => 0, 2 => 0, 3 => 0, 4 => 0], $grid->totalsByMeal());
        $this->assertSame([], $grid->rooms[1]->otherRoom[1]['2026-05-30']);
    }

    // ---- getSelectableUsers ----

    public function testGetSelectableUsers_excludesDeletedAndSortsByName(): void
    {
        $users = $this->query->getSelectableUsers();

        $this->assertArrayNotHasKey(6, $users);
        $this->assertSame('管理者ユーザー', $users[1]);
        $sorted = $users;
        asort($sorted, SORT_STRING);
        $this->assertSame(array_keys($sorted), array_keys($users));
    }
}
