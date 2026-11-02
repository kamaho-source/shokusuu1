<?php
declare(strict_types=1);

namespace App\Test\TestCase\Infrastructure\Query;

use App\Application\Query\Dto\ChangeEditMember;
use App\Domain\Exception\NotFoundException;
use App\Infrastructure\Query\CakeChangeEditQuery;
use Cake\Chronos\ClockFactory;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * 直前編集の画面の読み取り（CakeChangeEditQuery）。旧 ReservationChangeEditServiceTest の読み取りの部分を引き継ぐ。
 */
class CakeChangeEditQueryTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TIndividualReservationInfo',
    ];

    private CakeChangeEditQuery $query;

    protected function setUp(): void
    {
        parent::setUp();
        $this->query = new CakeChangeEditQuery(new ClockFactory('Asia/Tokyo'));
    }

    private function insertRoom(int $id): void
    {
        ConnectionManager::get('test')->insert('m_room_info', [
            'i_id_room' => $id, 'c_room_name' => '部屋' . $id, 'i_disp_no' => $id, 'i_enable' => 1, 'i_del_flg' => 0,
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't',
        ]);
    }

    private function insertMeal(int $roomId, int $userId, int $type, string $date, int $eat = 1, ?int $change = 1): void
    {
        ConnectionManager::get('test')->insert('t_individual_reservation_info', [
            'i_id_user' => $userId, 'd_reservation_date' => $date, 'i_reservation_type' => $type, 'i_id_room' => $roomId,
            'eat_flag' => $eat, 'i_change_flag' => $change, 'i_version' => 1,
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't',
        ]);
    }

    // ---- defaultRoomId ----

    public function testDefaultRoomId_guards(): void
    {
        $this->assertNull($this->query->defaultRoomId([], '2099-01-01'));
        $this->assertSame(5, $this->query->defaultRoomId([5 => 'ルームA'], '2099-01-01'));
        $this->assertSame(10, $this->query->defaultRoomId([10 => 'ルームA', 20 => 'ルームB'], '2099-12-31'), '予約が無ければ先頭');
    }

    public function testDefaultRoomId_prefersRoomWithReservationOnDate(): void
    {
        $this->insertRoom(2);
        $this->insertMeal(2, 1, 1, '2030-05-05');

        $this->assertSame(2, $this->query->defaultRoomId([1 => 'a', 2 => 'b'], '2030-05-05'));
        $this->assertSame(1, $this->query->defaultRoomId([1 => 'a', 2 => 'b'], '2030-05-06'));
    }

    // ---- roomName ----

    public function testRoomName(): void
    {
        $this->assertSame('Lorem ipsum dolor sit amet', $this->query->roomName(1));
        $this->assertNull($this->query->roomName(999));
    }

    // ---- context ----

    public function testContext_returnsRoomMembersAndReservations(): void
    {
        $this->insertRoom(2);
        $this->insertMeal(1, 3, 1, '2030-05-05');
        $this->insertMeal(2, 3, 3, '2030-05-05', 0, null);
        $this->insertMeal(1, 3, 1, '2030-05-06', 0, 0); // 別の日は混ぜない

        $ctx = $this->query->context(1, '2030-05-05', 0, 0, -1);

        $this->assertSame([1, 'Lorem ipsum dolor sit amet'], [$ctx->roomId, $ctx->roomName]);
        // 削除済みユーザー(6)は含まれず、表示順（i_disp_no）で並ぶ（#871。名前順なら 3 が先頭になる）
        $this->assertSame([1, 2, 3], $ctx->memberIds());
        $this->assertSame([1, '一般ユーザー', 1], [$ctx->members[2]->level, $ctx->members[2]->name, $ctx->members[2]->level]);
        $this->assertSame([
            1 => ['room_id' => 1, 'eat_flag' => 1, 'room_name' => 'Lorem ipsum dolor sit amet', 'i_change_flag' => 1],
            3 => ['room_id' => 2, 'eat_flag' => 0, 'room_name' => '部屋2', 'i_change_flag' => null],
        ], $ctx->reservations[3], '他の部屋の予約も、どの部屋かと一緒に返す');
    }

    /** 行ごとの allowEdit は見る人で決まる（部屋1の利用者: 1=職員, 2=職員, 3=子ども） */
    public function testContext_allowEditDependsOnViewer(): void
    {
        $allow = fn(int $uid, int $admin, int $level): array => array_map(
            static fn(ChangeEditMember $m): bool => $m->allowEdit,
            $this->query->context(1, '2030-05-05', $uid, $admin, $level)->members,
        );

        $this->assertSame([false, true, true], $allow(2, 0, 0), '職員は本人と子どもだけ');
        $this->assertSame([true, true, true], $allow(1, 1, 0), '管理者は全員');
        $this->assertSame([true, true, true], $allow(2, 2, 0), '部屋に所属するブロック長は全員');
        $this->assertSame([false, false, true], $allow(9, 2, 0), '部屋に所属しないブロック長は全員を編集できない');
        $this->assertSame([false, false, false], $allow(0, 0, -1), '見る人が分からなければ誰も編集できない');
    }

    public function testContext_excludesLeftMembers(): void
    {
        ConnectionManager::get('test')->update('m_user_group', ['active_flag' => 1], ['i_id_user' => 2]);

        $this->assertSame([1, 3], $this->query->context(1, '2030-05-05', 0, 0, -1)->memberIds());
    }

    public function testContext_unknownRoom_throwsNotFound(): void
    {
        $this->expectException(NotFoundException::class);

        $this->query->context(999, '2030-05-05', 0, 0, -1);
    }

    public function testContext_roomWithoutMembers_returnsNoUsers(): void
    {
        $this->insertRoom(5);

        $ctx = $this->query->context(5, '2030-05-05', 0, 0, -1);

        $this->assertSame([], $ctx->members);
        $this->assertSame([], $ctx->reservations);
    }

    // ---- ownMeals ----

    /** 本人のその日に食べる予約だけ（先の日は eat_flag、直前・過去は i_change_flag で判定） */
    public function testOwnMeals_onlyEatenMeals(): void
    {
        $this->insertRoom(2);
        $this->insertMeal(1, 3, 1, '2030-05-05', 1, 0);    // 先の日は eat_flag=1 → 食べる
        $this->insertMeal(2, 3, 2, '2030-05-05', 0, 1);    // 先の日は eat_flag=0 → 食べない
        $this->insertMeal(1, 2, 3, '2030-05-05', 1, 1);    // 他人
        $this->insertMeal(1, 3, 1, '2026-01-05', 1, 0);    // 過去日は i_change_flag=0 → 食べない
        $this->insertMeal(2, 3, 3, '2026-01-05', 0, 1);    // 過去日は i_change_flag=1 → 食べる

        $this->assertSame([['type' => 1, 'room_id' => 1]], $this->query->ownMeals(3, '2030-05-05'));
        $this->assertSame([['type' => 3, 'room_id' => 2]], $this->query->ownMeals(3, '2026-01-05'));
        $this->assertSame([], $this->query->ownMeals(3, 'abc'));
    }

    public function testMemberToJson(): void
    {
        $this->assertSame(
            ['id' => 5, 'name' => '職員', 'i_user_level' => 7, 'userLevel' => 7, 'isStaff' => true, 'allowEdit' => false],
            (new ChangeEditMember(5, '職員', 7, false))->toJson()
        );
        $this->assertFalse((new ChangeEditMember(2, '子ども', 1, true))->toJson()['isStaff']);
    }
}
