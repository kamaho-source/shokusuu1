<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Domain\Exception\InvalidInputException;
use App\Service\MealCountGridService;
use App\Service\RoomMemberOrderService;
use App\Service\RoomService;
use Cake\TestSuite\TestCase;

/**
 * フィクスチャの部屋1: 職員 1(表示順1)・2(表示順2)、児童 3(表示順3)、削除済み 6。部屋に属さない 4・5。
 */
class RoomMemberOrderServiceTest extends TestCase
{
    protected array $fixtures = [
        'app.MRoomInfo',
        'app.MUserGroup',
        'app.MUserInfo',
        'app.TIndividualReservationInfo',
    ];

    /** 部屋1にユーザーを足す（i_user_level・表示順を指定） */
    private function addMember(int $id, ?int $level, ?int $dispNo, string $name): void
    {
        $users = $this->getTableLocator()->get('MUserInfo');
        $users->saveOrFail($users->newEntity([
            'i_id_user' => $id, 'c_login_account' => "u{$id}", 'c_login_passwd' => 'x', 'c_user_name' => $name,
            'i_admin' => 0, 'i_user_level' => $level, 'i_disp_no' => $dispNo, 'i_enable' => 0, 'i_del_flag' => 0,
            'dt_create' => '2024-01-01 00:00:00', 'c_create_user' => 't', 'dt_update' => '2024-01-01 00:00:00', 'c_update_user' => 't',
        ], ['accessibleFields' => ['i_id_user' => true], 'validate' => false]));
        $groups = $this->getTableLocator()->get('MUserGroup');
        $groups->saveOrFail($groups->newEntity([
            'i_id_user' => $id, 'i_id_room' => 1, 'active_flag' => 0,
            'dt_create' => '2024-01-01 00:00:00', 'c_create_user' => 't', 'dt_update' => '2024-01-01 00:00:00', 'c_update_user' => 't',
        ]));
    }

    private function dispNos(): array
    {
        return $this->getTableLocator()->get('MUserInfo')->find('list', keyField: 'i_id_user', valueField: 'i_disp_no')->toArray();
    }

    public function testReorderAcrossLevels(): void
    {
        // 児童3 を職員より前に送れる（区分をまたいで並べられる）
        $changed = (new RoomMemberOrderService())->reorder(1, [3, 2, 1], 'tester');

        $this->assertSame(2, $changed);
        $d = $this->dispNos();
        $this->assertSame([3, 2, 1], [$d[1], $d[2], $d[3]]);
        $this->assertSame([4, 5, 6], [$d[4], $d[5], $d[6]], '部屋に所属しない人・削除済みの人の番号は変えない');
        $this->assertSame('tester', $this->getTableLocator()->get('MUserInfo')->get(1)->c_update_user);
    }

    public function testSameOrderChangesNothing(): void
    {
        $this->assertSame(0, (new RoomMemberOrderService())->reorder(1, [1, 2, 3], 'tester'));
    }

    public function testNullNumbersAreAssignedAfterMax(): void
    {
        $this->getTableLocator()->get('MUserInfo')->updateAll(['i_disp_no' => null], ['i_id_user IN' => [1, 2]]);

        (new RoomMemberOrderService())->reorder(1, [2, 1, 3], 'tester');

        $d = $this->dispNos();
        $this->assertSame([3, 7, 8], [$d[2], $d[1], $d[3]], '今ある番号(3)を先頭から配り、足りない分は全員の最大値(6)の後ろに振る');
    }

    /** @return array<string, array{list<int>}> */
    public static function invalidOrders(): array
    {
        return [
            '所属していない人が入っている' => [[1, 2, 3, 4]],
            '削除済みの人が入っている'     => [[1, 2, 3, 6]],
            '所属メンバーが足りない'       => [[1, 2]],
            '同じ人が2回'                  => [[1, 1, 3]],
        ];
    }

    /** @dataProvider invalidOrders */
    public function testRejectsOrderThatDoesNotMatchMembers(array $userIds): void
    {
        try {
            (new RoomMemberOrderService())->reorder(1, $userIds, 'tester');
            $this->fail('例外になること');
        } catch (InvalidInputException $e) {
            $this->assertSame(422, $e->getStatusCode());
        }
        $d = $this->dispNos();
        $this->assertSame([1, 2, 3], [$d[1], $d[2], $d[3]], '保存しない');
    }

    public function testRejectsWithdrawnMember(): void
    {
        $this->getTableLocator()->get('MUserGroup')->updateAll(['active_flag' => 1], ['i_id_user' => 2, 'i_id_room' => 1]);

        $this->expectException(InvalidInputException::class);
        (new RoomMemberOrderService())->reorder(1, [2, 1, 3], 'tester');
    }

    /** 保存した並びが、エクセル食数予約・部屋情報の取得順に出る（NULL は後ろ） */
    public function testSavedOrderIsUsedByMealGridAndRoomView(): void
    {
        $users = $this->getTableLocator()->get('MUserInfo');
        $users->updateAll(['i_disp_no' => null], ['i_id_user' => 1]);
        $users->updateAll(['c_user_name' => 'あ'], ['i_id_user' => 2]); // 名前順なら 2 が先だが、表示順の小さい児童3 が先
        $users->updateAll(['i_disp_no' => 9], ['i_id_user' => 2]);

        $grid = (new MealCountGridService())->getRoomUsersByRooms($this->getTableLocator()->get('MUserGroup'), $users, [1])[1];
        $this->assertSame([3, 2, 1], array_column($grid, 'id'), '区分で分けず表示順だけで並ぶ（NULL の 1 は後ろ）');

        (new RoomMemberOrderService())->reorder(1, [1, 2, 3], 'tester');
        $grid = (new MealCountGridService())->getRoomUsersByRooms($this->getTableLocator()->get('MUserGroup'), $users, [1])[1];
        $this->assertSame([1, 2, 3], array_column($grid, 'id'));

        $room = $this->getTableLocator()->get('MRoomInfo')->get(1, contain: ['MUserGroup']);
        $this->assertSame([1, 2, 3], array_map(fn($u) => (int)$u->i_id_user, (new RoomService())->getUsersForRoom($room)), '削除済み 6 は出ない');
    }

    /** 区分（0・1・7・NULL）が混ざる部屋で、区分をまたいで混ぜた並びをそのまま保存する */
    public function testReorderMixedLevels(): void
    {
        $this->addMember(31, 1, 31, '児童B');
        $this->addMember(71, 7, 71, '職員7A');
        $this->addMember(72, 7, null, '職員7B');
        $this->addMember(90, null, 90, '区分なし');

        $order = [72, 31, 2, 90, 3, 71, 1];
        (new RoomMemberOrderService())->reorder(1, $order, 'tester');

        // 今の番号（1・2・3・31・71・90）を並べた順に配り直し、足りない1つは最大値の後ろ（91）
        $d = $this->dispNos();
        $this->assertSame([1, 2, 3, 31, 71, 90, 91], array_map(fn($id) => $d[$id], $order));

        $grid = (new MealCountGridService())->getRoomUsersByRooms($this->getTableLocator()->get('MUserGroup'), $this->getTableLocator()->get('MUserInfo'), [1])[1];
        $this->assertSame($order, array_column($grid, 'id'));
    }

    /** 食数予約の各画面のメンバー一覧が、同じ並び（表示順 → 名前。区分では分けない）になる */
    public function testReservationScreensUseSameOrder(): void
    {
        $users = $this->getTableLocator()->get('MUserInfo');
        $groups = $this->getTableLocator()->get('MUserGroup');
        $reservations = $this->getTableLocator()->get('TIndividualReservationInfo');
        $users->updateAll(['i_disp_no' => 10], ['i_id_user' => 1]); // 職員1 を児童3 より後ろにする
        $users->updateAll(['i_id_staff' => 1001], ['i_id_user IN' => [1, 2]]);
        $expected = [2, 3, 1];

        $query = new \App\Service\ReservationQueryService();
        $this->assertSame($expected, array_column($query->getUsersByRoom($groups, $reservations, 1, null), 'id'), '予約モーダル');
        $paged = array_merge(
            array_column($query->getUsersByRoomForBulk($groups, $reservations, 1, null, 1, 2)['users'], 'id'),
            array_column($query->getUsersByRoomForBulk($groups, $reservations, 1, null, 2, 2)['users'], 'id'),
        );
        $this->assertSame($expected, $paged, '一括登録（ページ送りで重複・抜けなし）');

        $context = (new \App\Service\ReservationChangeEditService())->buildContext(1, '2026-05-11', $this->getTableLocator()->get('MRoomInfo'), $groups, $users, $reservations);
        $this->assertSame($expected, $context['userIdList'], '予約変更');

        $this->assertSame($expected, array_column((new \App\Service\ReservationReportService())->getUsersByRoomForEdit($groups, $reservations, 1, '2026-05-11'), 'id'), '直前編集API');
        $this->assertSame([2, 1], array_column((new \App\Service\ActualMealManagementService())->getAdultUsers($groups, $users, 1), 'id'), '実績管理');
    }
}
