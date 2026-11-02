<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\SaveChangeEdit;

use App\Application\UseCase\SaveChangeEdit\SaveChangeEditInput;
use App\Domain\Exception\ApprovedReservationChangeException;
use App\Domain\Exception\ConflictException;
use App\Infrastructure\ReservationUseCases;
use Cake\ORM\Table;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\Date;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * 直前編集の保存（SaveChangeEditUseCase）を実際の DB で確かめる。旧 ReservationChangeEditServiceTest を引き継ぐ。
 *
 * 画面の読み取りは CakeChangeEditQueryTest、権限は ChangeEditPermissionTest。
 */
class SaveChangeEditIntegrationTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TIndividualReservationInfo',
        'app.TAuditLog',
    ];

    /**
     * 以前の processUpdate と同じ引数で保存し、同じ形の結果を返す。
     *
     * @return array{updated: list<string>, created: list<string>, skipped: list<string>}
     */
    private function process(array $usersData, array $memberIds, string $date, int $roomId, ?object $login, Table $reservations, Table $users, bool $editsAll = false): array
    {
        $out = (new ReservationUseCases($reservations, $users))->saveChangeEdit()->execute(new SaveChangeEditInput(
            usersData: $usersData,
            memberIds: $memberIds,
            date: $date,
            roomId: $roomId,
            loginUserId: (int)($login?->get('i_id_user') ?? 0),
            loginUserName: (string)($login?->get('c_user_name') ?? 'system'),
            loginAdmin: (int)($login?->get('i_admin') ?? 0),
            loginLevel: (int)($login?->get('i_user_level') ?? -1),
            isBlockLeaderInRoom: $editsAll,
        ));

        return ['updated' => $out->updated, 'created' => $out->created, 'skipped' => $out->skipped];
    }

    // ----------------------------------------------------------------
    // processUpdate — 承認済み保護（バグ1）
    // ----------------------------------------------------------------

    /**
     * バグ1: 直前編集（processUpdate）も承認済みレコードを変更できない。
     */
    public function testProcessUpdate_approvedReservation_throwsAndKeepsRow(): void
    {
        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $userTable        = TableRegistry::getTableLocator()->get('MUserInfo');

        $date = Date::today()->addDays(20)->format('Y-m-d');
        ConnectionManager::get('test')->insert('t_individual_reservation_info', [
            'i_id_user'          => 3,
            'd_reservation_date' => $date,
            'i_reservation_type' => 1,
            'i_id_room'          => 1,
            'eat_flag'           => 1,
            'i_change_flag'      => 1,
            'i_approval_status'  => 2,
            'i_version'          => 1,
            'dt_create'          => '2026-06-01 00:00:00',
            'c_create_user'      => 'test',
        ]);

        $this->expectException(ApprovedReservationChangeException::class);

        try {
            $this->process(
                [3 => [1 => ['i_change_flag' => 0]]],
                [3],
                $date,
                1,
                null,
                $reservationTable,
                $userTable,
                true // 全員を編集できる人（その部屋のブロック長）
            );
        } finally {
            $row = $reservationTable->find()
                ->where([
                    'i_id_user' => 3,
                    'd_reservation_date' => $date,
                    'i_reservation_type' => 1,
                    'i_id_room' => 1,
                ])
                ->first();
            $this->assertSame(1, (int)$row->i_change_flag, '承認済みの予約が変更されている');
        }
    }

    // ----------------------------------------------------------------
    // processUpdate — 同じ食事を複数の部屋で同時に有効にしない（部屋の移動として扱う）
    // ----------------------------------------------------------------

    /** 直前期間（当日〜14日先）の日付 */
    private function nearDate(): string
    {
        return Date::today()->addDays(3)->format('Y-m-d');
    }

    private function insertMeal(int $roomId, int $eat, int $chg, ?string $date = null): void
    {
        ConnectionManager::get('test')->insert('t_individual_reservation_info', [
            'i_id_user'          => 3,
            'd_reservation_date' => $date ?? $this->nearDate(),
            'i_reservation_type' => 1,
            'i_id_room'          => $roomId,
            'eat_flag'           => $eat,
            'i_change_flag'      => $chg,
            'i_approval_status'  => 0,
            'i_version'          => 1,
            'dt_create'          => '2026-01-01 00:00:00',
            'c_create_user'      => 'test',
        ]);
    }

    private function eatInRoom(int $roomId, ?string $date = null): void
    {
        $this->process(
            [3 => [1 => ['i_change_flag' => 1]]],
            [3],
            $date ?? $this->nearDate(),
            $roomId,
            null,
            TableRegistry::getTableLocator()->get('TIndividualReservationInfo'),
            TableRegistry::getTableLocator()->get('MUserInfo'),
            true
        );
    }

    /** @return array<int, array{int, int}> [roomId => [eat_flag, i_change_flag]] */
    private function flagsByRoom(?string $date = null): array
    {
        $rows = TableRegistry::getTableLocator()->get('TIndividualReservationInfo')->find()
            ->where(['i_id_user' => 3, 'd_reservation_date' => $date ?? $this->nearDate(), 'i_reservation_type' => 1])
            ->orderByAsc('i_id_room')
            ->all();
        $map = [];
        foreach ($rows as $r) {
            $map[(int)$r->i_id_room] = [(int)$r->eat_flag, (int)$r->i_change_flag];
        }

        return $map;
    }

    /** 部屋Aが有効（eat0/chg1）のまま部屋Bの行（当初予定あり・直前取消 1/0）を「食べる」にすると、Aが取り消される */
    public function testProcessUpdate_eatInOtherRoom_cancelsEffectiveRoom(): void
    {
        $this->insertMeal(1, 0, 1);
        $this->insertMeal(2, 1, 0);

        $this->eatInRoom(2);

        $this->assertSame([1 => [0, 0], 2 => [1, 1]], $this->flagsByRoom());
    }

    /** 部屋Bに取消済み行（0/0）があっても「食べる」にでき、部屋Aが取り消される */
    public function testProcessUpdate_eatInRoomWithCancelledRow_cancelsEffectiveRoom(): void
    {
        $this->insertMeal(1, 0, 1);
        $this->insertMeal(2, 0, 0);

        $this->eatInRoom(2);

        $this->assertSame([1 => [0, 0], 2 => [0, 1]], $this->flagsByRoom());
    }

    /** 行を書き換えて移すときは、取消済み行（部屋1）ではなく有効な行（部屋2）を移す */
    public function testProcessUpdate_movesEffectiveRowRatherThanCancelledRow(): void
    {
        // 移せるのは、利用者が所属している部屋の予約だけ（退出済み・削除済みの部屋の予約は取消だけ許す）
        $conn = ConnectionManager::get('test');
        $conn->insert('m_room_info', ['i_id_room' => 2, 'c_room_name' => '部屋2', 'i_disp_no' => 2, 'i_enable' => 1, 'i_del_flg' => 0, 'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't']);
        $conn->insert('m_user_group', ['i_id_user' => 3, 'i_id_room' => 2, 'active_flag' => 0, 'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't']);
        $this->insertMeal(1, 0, 0);
        $this->insertMeal(2, 0, 1);

        $this->eatInRoom(4);

        $this->assertSame([1 => [0, 0], 4 => [0, 1]], $this->flagsByRoom());
    }

    /** 直前期間外は eat_flag が有効値。部屋を移しても、どの部屋でも食べない状態にしない */
    public function testProcessUpdate_farDate_movingKeepsTheReservation(): void
    {
        $far = \Cake\I18n\Date::today()->addDays(30)->format('Y-m-d');
        $this->insertMeal(1, 1, 1, $far);
        $this->insertMeal(2, 0, 0, $far);

        $this->eatInRoom(2, $far);

        $this->assertSame([1 => [0, 0], 2 => [1, 1]], $this->flagsByRoom($far));
    }

    /** 職員（user 2）は直前期間に部屋を移せない（change-room API と同じ扱い）。部屋1の予約が残り、部屋2には作らない */
    public function testProcessUpdate_staffLastMinute_cannotMoveRoom(): void
    {
        $date = $this->nearDate();
        ConnectionManager::get('test')->insert('t_individual_reservation_info', [
            'i_id_user' => 2, 'd_reservation_date' => $date, 'i_reservation_type' => 1, 'i_id_room' => 1,
            'eat_flag' => 0, 'i_change_flag' => 1, 'i_approval_status' => 0, 'i_version' => 1,
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 'test',
        ]);
        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');

        $this->process(
            [2 => [1 => ['i_change_flag' => 1]]],
            [2],
            $date,
            2,
            null,
            $reservationTable,
            TableRegistry::getTableLocator()->get('MUserInfo'),
            true
        );

        $rows = $reservationTable->find()
            ->where(['i_id_user' => 2, 'd_reservation_date' => $date, 'i_reservation_type' => 1])
            ->all()
            ->combine('i_id_room', fn($r) => [(int)$r->eat_flag, (int)$r->i_change_flag])
            ->toArray();
        $this->assertSame([1 => [0, 1]], $rows);
    }

    /** 同じ保存で「弁当を外して昼食を入れる」と、取消を先に行うので拒否されない（児童 user 3） */
    public function testProcessUpdate_swapBentoToLunchInOneSave(): void
    {
        $date = $this->nearDate();
        foreach ([[4, 1], [2, 0]] as [$meal, $flag]) {
            ConnectionManager::get('test')->insert('t_individual_reservation_info', [
                'i_id_user' => 3, 'd_reservation_date' => $date, 'i_reservation_type' => $meal, 'i_id_room' => 1,
                'eat_flag' => 0, 'i_change_flag' => $flag, 'i_approval_status' => 0, 'i_version' => 1,
                'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 'test',
            ]);
        }
        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');

        $this->process(
            [3 => [2 => ['i_change_flag' => 1], 4 => ['i_change_flag' => 0]]],
            [3],
            $date,
            1,
            null,
            $reservationTable,
            TableRegistry::getTableLocator()->get('MUserInfo'),
            true
        );

        $this->assertSame([1], $reservationTable->findEffectiveRoomIds(3, $date, 2));
        $this->assertSame([], $reservationTable->findEffectiveRoomIds(3, $date, 4));
    }

    /** 管理者以外は、過ぎた日の予約を直前編集で変更できない */
    public function testProcessUpdate_rejectsPastDateForNonAdmin(): void
    {
        $date = Date::today()->subDays(3)->format('Y-m-d');
        $this->insertMeal(1, 1, 1, $date);

        $this->expectException(\App\Domain\Exception\InvalidInputException::class);
        $this->expectExceptionMessage('過去日の予約は変更できません。');
        $this->process(
            [3 => [1 => ['i_change_flag' => 0]]], [3], $date, 1, null,
            TableRegistry::getTableLocator()->get('TIndividualReservationInfo'),
            TableRegistry::getTableLocator()->get('MUserInfo'),
            true
        );
    }

    /** 管理者が過ぎた日を直すときも、当初予定（eat_flag）は残し、i_change_flag だけを変える */
    public function testProcessUpdate_adminPastDateKeepsOriginalPlan(): void
    {
        $date = Date::today()->subDays(3)->format('Y-m-d');
        $this->insertMeal(1, 1, 1, $date);
        $admin = new class {
            public function get(string $key): int|string|null
            {
                return ['i_id_user' => 1, 'i_admin' => 1, 'i_user_level' => 0, 'c_user_name' => '管理者'][$key] ?? null;
            }
        };

        $this->process(
            [3 => [1 => ['i_change_flag' => 0]]], [3], $date, 1, $admin,
            TableRegistry::getTableLocator()->get('TIndividualReservationInfo'),
            TableRegistry::getTableLocator()->get('MUserInfo'),
            true
        );

        $this->assertSame([1 => [1, 0]], $this->flagsByRoom($date));
    }

    /** 直前編集で、退出済みの部屋に残った予約を黙ってこの部屋へ移さない（取消だけ許す） */
    public function testProcessUpdate_doesNotMoveFromExitedRoom(): void
    {
        $date = $this->nearDate();
        $conn = ConnectionManager::get('test');
        $conn->insert('m_room_info', ['i_id_room' => 8, 'c_room_name' => '退出', 'i_disp_no' => 8, 'i_enable' => 1, 'i_del_flg' => 0, 'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't']);
        $conn->insert('m_user_group', ['i_id_user' => 3, 'i_id_room' => 8, 'active_flag' => 1, 'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't']);
        $this->insertMeal(8, 0, 1);

        $result = $this->process(
            [3 => [1 => ['i_change_flag' => 1]]], [3], $date, 1, null,
            TableRegistry::getTableLocator()->get('TIndividualReservationInfo'),
            TableRegistry::getTableLocator()->get('MUserInfo'),
            true
        );

        $this->assertSame([8 => [0, 1]], $this->flagsByRoom());
        $this->assertStringContainsString('所属外の部屋', implode('', $result['skipped'] ?? []));
    }

    // ----------------------------------------------------------------
    // 追加: 部屋の解決・コンテキスト構築・processUpdate の分岐
    // ----------------------------------------------------------------

    private function loginAs(int $id, int $admin, int $level, string $name = 'login'): object
    {
        return new class ($id, $admin, $level, $name) {
            public function __construct(private int $id, private int $admin, private int $level, private string $name)
            {
            }

            public function get(string $key): int|string|null
            {
                return match ($key) {
                    'i_id_user' => $this->id,
                    'i_admin' => $this->admin,
                    'i_user_level' => $this->level,
                    'c_user_name' => $this->name,
                    default => null,
                };
            }
        };
    }

    private function resTable(): \App\Infrastructure\Persistence\Table\TIndividualReservationInfoTable
    {
        /** @var \App\Infrastructure\Persistence\Table\TIndividualReservationInfoTable $t */
        $t = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');

        return $t;
    }

    private function runUpdate(array $data, array $ids, string $date, int $room, ?object $login, bool $manager = false): array
    {
        return $this->process(
            $data,
            $ids,
            $date,
            $room,
            $login,
            $this->resTable(),
            TableRegistry::getTableLocator()->get('MUserInfo'),
            $manager
        );
    }

    private function insertRoom(int $id, string $name = '部屋'): void
    {
        ConnectionManager::get('test')->insert('m_room_info', [
            'i_id_room' => $id, 'c_room_name' => $name . $id, 'i_disp_no' => $id, 'i_enable' => 1, 'i_del_flg' => 0,
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't',
        ]);
    }

    public function testProcessUpdate_userNotInRoom_isSkipped(): void
    {
        $result = $this->runUpdate([3 => [1 => ['i_change_flag' => 1]]], [2], '2030-05-05', 1, null, true);

        $this->assertSame([], $result['created']);
        $this->assertSame([], $result['updated']);
        $this->assertStringContainsString('所属ではない', $result['skipped'][0]);
        $this->assertSame([], $this->flagsByRoom('2030-05-05'));
    }

    public function testProcessUpdate_nonManagerCannotEditOtherStaff(): void
    {
        // 児童(3)が職員(2)の予約を変更しようとする
        $result = $this->runUpdate([2 => [1 => ['i_change_flag' => 1]]], [2, 3], '2030-05-05', 1, $this->loginAs(3, 0, 1));

        $this->assertStringContainsString('権限がありません', $result['skipped'][0]);
        $this->assertSame([], $result['created']);
    }

    public function testProcessUpdate_staffCanEditChildAndSelf(): void
    {
        $date = '2030-05-05';
        $result = $this->runUpdate(
            [3 => [1 => ['i_change_flag' => 1]], 2 => [2 => ['i_change_flag' => 1]]],
            [2, 3],
            $date,
            1,
            $this->loginAs(2, 0, 0, '職員')
        );

        $this->assertEqualsCanonicalizing(['3:1', '2:2'], $result['created']);
        $this->assertSame([], $result['skipped']);
    }

    public function testProcessUpdate_invalidFlagAndInvalidMealAreIgnored(): void
    {
        $result = $this->runUpdate(
            [3 => [1 => ['i_change_flag' => 5], 9 => ['i_change_flag' => 1], 2 => ['x' => 1]]],
            [3],
            '2030-05-05',
            1,
            null,
            true
        );

        $this->assertCount(1, $result['skipped']);
        $this->assertStringContainsString('不正', $result['skipped'][0]);
        $this->assertSame([], $result['created']);
    }

    public function testProcessUpdate_createNewRow_farDateSetsEatFlag_nearDateKeepsOriginalPlan(): void
    {
        $far = Date::today()->addDays(40)->format('Y-m-d');
        $near = $this->nearDate();

        $this->runUpdate([3 => [1 => ['i_change_flag' => 1]]], [3], $far, 1, null, true);
        $this->runUpdate([3 => [1 => ['i_change_flag' => 1]]], [3], $near, 1, null, true);

        $this->assertSame([1 => [1, 1]], $this->flagsByRoom($far));
        $this->assertSame([1 => [0, 1]], $this->flagsByRoom($near));
    }

    public function testProcessUpdate_flag2MeansCancel_andNoRowMeansNothingCreated(): void
    {
        $date = $this->nearDate();
        $this->insertMeal(1, 1, 1);

        $result = $this->runUpdate([3 => [1 => ['i_change_flag' => 2], 2 => ['i_change_flag' => 0]]], [3], $date, 1, null, true);

        $this->assertSame(['3:1'], $result['updated']);
        $this->assertSame([], $result['created']);
        $this->assertSame([1 => [1, 0]], $this->flagsByRoom());
    }

    public function testProcessUpdate_sameValue_isNotUpdated(): void
    {
        $this->insertMeal(1, 1, 1);

        $result = $this->runUpdate([3 => [1 => ['i_change_flag' => 1]]], [3], $this->nearDate(), 1, null, true);

        $this->assertSame([], $result['updated']);
        $this->assertSame([1 => [1, 1]], $this->flagsByRoom());
    }

    public function testProcessUpdate_staffCannotCancelInLastMinute_butAdminCan(): void
    {
        $date = $this->nearDate();
        ConnectionManager::get('test')->insert('t_individual_reservation_info', [
            'i_id_user' => 2, 'd_reservation_date' => $date, 'i_reservation_type' => 1, 'i_id_room' => 1,
            'eat_flag' => 1, 'i_change_flag' => 1, 'i_approval_status' => 0, 'i_version' => 1,
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 'test',
        ]);

        $denied = $this->runUpdate([2 => [1 => ['i_change_flag' => 0]]], [2], $date, 1, $this->loginAs(2, 0, 0));
        $this->assertStringContainsString('キャンセルはできません', $denied['skipped'][0]);
        $this->assertSame([], $denied['updated']);

        $allowed = $this->runUpdate([2 => [1 => ['i_change_flag' => 0]]], [2], $date, 1, $this->loginAs(1, 1, 0));
        $this->assertSame(['2:1'], $allowed['updated']);
    }

    public function testProcessUpdate_versionConflict_throwsAndRollsBack(): void
    {
        $this->insertMeal(1, 1, 1);
        $table = $this->getMockBuilder(\App\Infrastructure\Persistence\Table\TIndividualReservationInfoTable::class)
            ->onlyMethods(['updateRowWithVersion'])
            ->setConstructorArgs([['connection' => ConnectionManager::get('test')]])
            ->getMock();
        $table->method('updateRowWithVersion')->willReturn(false);

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('他のユーザーが先に保存しました。ページを再読込して再試行してください。');

        try {
            $this->process(
                [3 => [1 => ['i_change_flag' => 0]]],
                [3],
                $this->nearDate(),
                1,
                null,
                $table,
                TableRegistry::getTableLocator()->get('MUserInfo'),
                true
            );
        } finally {
            $this->assertSame([1 => [1, 1]], $this->flagsByRoom());
        }
    }

    /** 先の日（15日以降）は、i_change_flag が同じでも eat_flag がずれていればそろえる（そろえないと数える値が変わらない） */
    public function testFarDate_alignsEatFlagWhenOnlyItDiffers(): void
    {
        $far = Date::today()->addDays(30)->format('Y-m-d');
        $this->insertMeal(1, 0, 1, $far);

        $result = $this->process([3 => [1 => ['i_change_flag' => 1]]], [3], $far, 1, null, $this->resTable(), TableRegistry::getTableLocator()->get('MUserInfo'), true);

        $this->assertSame(['3:1'], $result['updated']);
        $this->assertSame([1 => [1, 1]], $this->flagsByRoom($far));
    }

    /** 区分が NULL の人は、以前どおり職員として扱う（直前期間の取消は管理者以外できない） */
    public function testNullLevelTarget_isTreatedAsStaff(): void
    {
        TableRegistry::getTableLocator()->get('MUserInfo')->updateAll(['i_user_level' => null], ['i_id_user' => 3]);
        $this->insertMeal(1, 1, 1);

        $result = $this->process([3 => [1 => ['i_change_flag' => 0]]], [3], $this->nearDate(), 1, $this->loginAs(9, 0, 0), $this->resTable(), TableRegistry::getTableLocator()->get('MUserInfo'), true);

        $this->assertSame([], $result['updated']);
        $this->assertStringContainsString('職員のため、直前編集でのキャンセルはできません', $result['skipped'][0] ?? '');
        $this->assertSame([1 => [1, 1]], $this->flagsByRoom());
    }
}
