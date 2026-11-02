<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\UpdateRoom;

use App\Application\Port\AuditActor;
use App\Application\UseCase\UpdateRoom\UpdateRoomInput;
use App\Application\UseCase\UpdateRoom\UpdateRoomOutput;
use App\Application\UseCase\UpdateRoom\UpdateRoomUseCase;
use App\Infrastructure\Log\AuditLogAdapter;
use App\Infrastructure\Persistence\CakeTransactionManager;
use App\Infrastructure\Repository\CakeRoomRepository;
use App\Infrastructure\Query\CakeMealCountGridQuery;
use App\Infrastructure\Query\CakeRoomQuery;
use Cake\Chronos\ClockFactory;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * 部屋の所属メンバーの並び替え（UpdateRoomUseCase）を、本物の DB で確かめる（旧 RoomMemberOrderServiceTest を引き継ぐ）。
 * フィクスチャの部屋1: 職員 1(表示順1)・2(表示順2)、児童 3(表示順3)、削除済み 6。部屋に属さない 4・5。
 */
class UpdateRoomReorderIntegrationTest extends TestCase
{
    protected array $fixtures = [
        'app.MRoomInfo',
        'app.MUserGroup',
        'app.MUserInfo',
        'app.TIndividualReservationInfo',
        'app.TAuditLog',
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

    /**
     * @param list<int> $userIds
     */
    private function reorder(array $userIds, bool $canRename = false, array $input = []): UpdateRoomOutput
    {
        $useCase = new UpdateRoomUseCase(new CakeRoomRepository(), new CakeTransactionManager(ConnectionManager::get('test')), new AuditLogAdapter(), new ClockFactory('Asia/Tokyo'));

        return $useCase->execute(new UpdateRoomInput(1, $input, $userIds, new AuditActor('tester', 1, 'admin_user', '127.0.0.1'), $canRename, true));
    }

    private function dispNos(): array
    {
        return $this->getTableLocator()->get('MUserInfo')->find('list', keyField: 'i_id_user', valueField: 'i_disp_no')->toArray();
    }

    public function testReorderAcrossLevels(): void
    {
        // 児童3 を職員より前に送れる（区分をまたいで並べられる）
        $changed = $this->reorder([3, 2, 1])->reordered;

        $this->assertSame(2, $changed);
        $d = $this->dispNos();
        $this->assertSame([3, 2, 1], [$d[1], $d[2], $d[3]]);
        $this->assertSame([4, 5, 6], [$d[4], $d[5], $d[6]], '部屋に所属しない人・削除済みの人の番号は変えない');
        $this->assertSame('tester', $this->getTableLocator()->get('MUserInfo')->get(1)->c_update_user);
    }

    public function testSameOrderChangesNothing(): void
    {
        $this->assertSame(0, $this->reorder([1, 2, 3])->reordered);
        $this->assertSame(0, $this->getTableLocator()->get('TAuditLog')->find()->where(['c_action' => 'room_member_reorder'])->count(), '並びが変わらなければ記録しない');
    }

    public function testNullNumbersAreAssignedAfterMax(): void
    {
        $this->getTableLocator()->get('MUserInfo')->updateAll(['i_disp_no' => null], ['i_id_user IN' => [1, 2]]);

        $this->reorder([2, 1, 3]);

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
        $result = $this->reorder($userIds);
        $this->assertSame(UpdateRoomOutput::REJECTED, $result->status);
        $this->assertSame('部屋の所属メンバーが変わっています。画面を読み込み直してください。', $result->message);
        $d = $this->dispNos();
        $this->assertSame([1, 2, 3], [$d[1], $d[2], $d[3]], '保存しない');
    }

    public function testRejectsWithdrawnMember(): void
    {
        $this->getTableLocator()->get('MUserGroup')->updateAll(['active_flag' => 1], ['i_id_user' => 2, 'i_id_room' => 1]);

        $this->assertSame(UpdateRoomOutput::REJECTED, $this->reorder([2, 1, 3])->status);
    }

    /** 保存した並びが、エクセル食数予約・部屋情報の取得順に出る（NULL は後ろ） */
    public function testSavedOrderIsUsedByMealGridAndRoomView(): void
    {
        $users = $this->getTableLocator()->get('MUserInfo');
        $users->updateAll(['i_disp_no' => null], ['i_id_user' => 1]);
        $users->updateAll(['c_user_name' => 'あ'], ['i_id_user' => 2]); // 名前順なら 2 が先だが、表示順の小さい児童3 が先
        $users->updateAll(['i_disp_no' => 9], ['i_id_user' => 2]);

        $grid = self::gridUserIds();
        $this->assertSame([3, 2, 1], $grid, '区分で分けず表示順だけで並ぶ（NULL の 1 は後ろ）');

        $this->reorder([1, 2, 3]);
        $grid = self::gridUserIds();
        $this->assertSame([1, 2, 3], $grid);

        $this->assertSame([1, 2, 3], array_map(fn($u) => $u->userId, (new CakeRoomQuery())->getRoomMembers(1)), '削除済み 6 は出ない');
    }

    /** 区分（0・1・7・NULL）が混ざる部屋で、区分をまたいで混ぜた並びをそのまま保存する */
    public function testReorderMixedLevels(): void
    {
        $this->addMember(31, 1, 31, '児童B');
        $this->addMember(71, 7, 71, '職員7A');
        $this->addMember(72, 7, null, '職員7B');
        $this->addMember(90, null, 90, '区分なし');

        $order = [72, 31, 2, 90, 3, 71, 1];
        $this->reorder($order);

        // 今の番号（1・2・3・31・71・90）を並べた順に配り直し、足りない1つは最大値の後ろ（91）
        $d = $this->dispNos();
        $this->assertSame([1, 2, 3, 31, 71, 90, 91], array_map(fn($id) => $d[$id], $order));

        $grid = self::gridUserIds();
        $this->assertSame($order, $grid);
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

        $query = new \App\Infrastructure\Query\CakeReservationReadQuery(new \App\Infrastructure\Query\CakeRoomQuery(), new \Cake\Chronos\ClockFactory('Asia/Tokyo'));
        $this->assertSame($expected, array_map(fn($u) => $u->userId, $query->getUsersByRoom(1, null)), '予約モーダル');
        $paged = array_merge(
            array_map(fn($u) => $u->userId, $query->getUsersByRoomForBulk(1, null, 1, 2)->users),
            array_map(fn($u) => $u->userId, $query->getUsersByRoomForBulk(1, null, 2, 2)->users),
        );
        $this->assertSame($expected, $paged, '一括登録（ページ送りで重複・抜けなし）');

        $context = (new \App\Service\ReservationChangeEditService())->buildContext(1, '2026-05-11', $this->getTableLocator()->get('MRoomInfo'), $groups, $users, $reservations);
        $this->assertSame($expected, $context['userIdList'], '予約変更');

        $this->assertSame($expected, array_map(static fn($u) => $u->userId, (new \App\Infrastructure\Query\CakeMealReportQuery(new \Cake\Chronos\ClockFactory('Asia/Tokyo')))->getUsersByRoomForEdit(1, '2026-05-11')), '直前編集API');
        $this->assertSame([2, 1], array_column((new \App\Service\ActualMealManagementService())->getAdultUsers($groups, $users, 1), 'id'), '実績管理');
    }

    /** 部屋名と並び順は1つのトランザクション。並び順が誤っていれば、部屋名の変更も保存しない */
    public function testRejectedOrder_rollsBackRename(): void
    {
        $result = $this->reorder([1, 2], canRename: true, input: ['c_room_name' => '新しい名前']);

        $this->assertSame(UpdateRoomOutput::REJECTED, $result->status);
        $this->assertSame('Lorem ipsum dolor sit amet', $this->getTableLocator()->get('MRoomInfo')->get(1)->c_room_name);
    }

    /** 名前を変えたときだけ保存・記録し、同じ名前なら更新しない */
    public function testRenameOnlyWhenChanged(): void
    {
        $rooms = $this->getTableLocator()->get('MRoomInfo');
        $audit = $this->getTableLocator()->get('TAuditLog');
        $this->reorder([1, 2, 3], canRename: true, input: ['c_room_name' => 'Lorem ipsum dolor sit amet']);
        $this->assertSame(0, $audit->find()->where(['c_action' => 'room_update'])->count());

        $this->reorder([1, 2, 3], canRename: true, input: ['c_room_name' => 'ひまわり', 'i_disp_no' => 99, 'i_enable' => 1]);
        $room = $rooms->get(1);
        $this->assertSame('ひまわり', $room->c_room_name);
        $this->assertSame('tester', $room->c_update_user);
        $this->assertNotSame(99, (int)$room->i_disp_no, '部屋名以外は受け取らない');
        $this->assertSame(1, $audit->find()->where(['c_action' => 'room_update'])->count());
    }

    public function testTooLongName_returnsFieldErrorWithoutSaving(): void
    {
        $result = $this->reorder([3, 2, 1], canRename: true, input: ['c_room_name' => str_repeat('あ', 51)]);

        $this->assertSame(UpdateRoomOutput::INVALID_FIELDS, $result->status);
        $this->assertArrayHasKey('c_room_name', $result->fieldErrors);
        $d = $this->dispNos();
        $this->assertSame([1, 2, 3], [$d[1], $d[2], $d[3]], '並び順も保存しない');
    }

    /** エクセル食数予約の部屋 1 の行の並び（利用者ID）。 */
    private static function gridUserIds(): array
    {
        $query = new CakeMealCountGridQuery(new \Cake\Chronos\ClockFactory('Asia/Tokyo'));

        return array_map(static fn($u) => $u->id, $query->getRoomUsers([1])[1]);
    }
}
