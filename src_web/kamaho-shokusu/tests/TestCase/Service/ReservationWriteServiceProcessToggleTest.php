<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Domain\Exception\ConflictException;
use App\Domain\Exception\DuplicateMealConflictException;
use App\Domain\Exception\InvalidInputException;
use App\Domain\Exception\UnauthorizedException;
use App\Service\ReservationWriteService;
use Cake\Datasource\ConnectionManager;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * ReservationWriteService::processToggle のテスト。
 */
class ReservationWriteServiceProcessToggleTest extends TestCase
{
    protected array $fixtures = [
        'app.TIndividualReservationInfo',
        'app.MRoomInfo',
        'app.MUserInfo',
        'app.MUserGroup',
    ];

    private ReservationWriteService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $userTable        = TableRegistry::getTableLocator()->get('MUserInfo');
        $roomTable        = TableRegistry::getTableLocator()->get('MRoomInfo');

        $this->service = new ReservationWriteService(
            $reservationTable,
            $userTable,
            $roomTable,
            '/webroot/'
        );
    }

    public function testProcessToggle_rejectsPastDate(): void
    {
        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('過去日の予約は変更できません。');

        $this->service->processToggle(
            roomId: 1,
            payload: [
                'date' => '2020-01-01',
                'meal' => 1,
                'value' => 1,
                'userId' => 1,
            ],
            loginUserId: 1,
            loginUserName: '管理者ユーザー',
        );
    }

    // ----------------------------------------------------------------
    // 退出済み・削除済みの部屋には書き込めない（画面の選択肢と基準を揃える）
    // ----------------------------------------------------------------

    private function addRoomMembership(int $userId, int $roomId, int $activeFlag, int $roomDelFlg): void
    {
        $db = ConnectionManager::get('test');
        $db->insert('m_room_info', [
            'i_id_room' => $roomId, 'c_room_name' => '部屋' . $roomId, 'i_disp_no' => $roomId,
            'i_enable' => 1, 'i_del_flg' => $roomDelFlg,
        ]);
        $db->insert('m_user_group', [
            'i_id_user' => $userId, 'i_id_room' => $roomId, 'active_flag' => $activeFlag,
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't', 'dt_update' => '2026-01-01 00:00:00', 'c_update_user' => 't',
        ]);
    }

    /** @return array<string, mixed> */
    private function futurePayload(): array
    {
        return ['date' => date('Y-m-d', strtotime('+20 days')), 'meal' => 3, 'value' => 1, 'userId' => 1];
    }

    public function testProcessToggle_activeMembershipInLiveRoom_succeeds(): void
    {
        $this->addRoomMembership(1, 30, 0, 0); // 正例（所属が有効・部屋も有効）

        $result = $this->service->processToggle(30, $this->futurePayload(), 1, '管理者ユーザー');

        $this->assertNotEmpty($result);
    }

    public function testProcessToggle_leftRoom_isRejected(): void
    {
        $this->addRoomMembership(1, 31, 1, 0); // 退出済み(active_flag=1)

        $this->expectException(UnauthorizedException::class);
        $this->service->processToggle(31, $this->futurePayload(), 1, '管理者ユーザー');
    }

    public function testProcessToggle_deletedRoom_isRejected(): void
    {
        $this->addRoomMembership(1, 32, 0, 1); // 部屋が削除済み(i_del_flg=1)

        $this->expectException(UnauthorizedException::class);
        $this->service->processToggle(32, $this->futurePayload(), 1, '管理者ユーザー');
    }

    /** グループ予約: 対象ユーザーが部屋に有効所属していないと、非管理者は書き込めない */
    public function testProcessGroupReservation_targetNotActiveMember_isRejected(): void
    {
        $this->addRoomMembership(2, 33, 0, 0); // 職員(user 2)は有効所属
        // 児童(user 3)は部屋33に所属していない

        $this->expectException(UnauthorizedException::class);
        $this->service->processGroupReservation(
            date('Y-m-d', strtotime('+20 days')),
            ['i_id_room' => 33, 'users' => [3 => [1 => 1]]],
            [33 => '部屋33'],
            '職員',
            fn() => true,
            2,
            false,
            0,
            false
        );
    }

    public function testProcessGroupReservation_targetActiveMember_succeeds(): void
    {
        $this->addRoomMembership(2, 34, 0, 0);
        ConnectionManager::get('test')->insert('m_user_group', [
            'i_id_user' => 3, 'i_id_room' => 34, 'active_flag' => 0,
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't', 'dt_update' => '2026-01-01 00:00:00', 'c_update_user' => 't',
        ]);

        $result = $this->service->processGroupReservation(
            date('Y-m-d', strtotime('+20 days')),
            ['i_id_room' => 34, 'users' => [3 => [1 => 1]]],
            [34 => '部屋34'],
            '職員',
            fn() => true,
            2,
            false,
            0,
            false
        );

        $this->assertNotEmpty($result);
    }

    /** @return array<string, mixed> */
    private function togglePayload(int $userId, int $value, int $meal = 3, int $days = 20): array
    {
        return ['date' => date('Y-m-d', strtotime("+{$days} days")), 'meal' => $meal, 'value' => $value, 'userId' => $userId];
    }

    private function addMember(int $userId, int $roomId, int $activeFlag): void
    {
        ConnectionManager::get('test')->insert('m_user_group', [
            'i_id_user' => $userId, 'i_id_room' => $roomId, 'active_flag' => $activeFlag,
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't', 'dt_update' => '2026-01-01 00:00:00', 'c_update_user' => 't',
        ]);
    }

    /** 取消（value=0）は、対象が退出済みでも行える。登録（value=1）は拒否される */
    public function testProcessToggle_cancelAllowedForLeftChild_registerRejected(): void
    {
        $this->addRoomMembership(2, 35, 0, 0);   // 職員(user 2)は有効所属
        $this->addMember(3, 35, 1);               // 児童(user 3)は退出済み
        $day = 20;
        $this->reservationTableInsert(3, 35, date('Y-m-d', strtotime("+{$day} days")), 3);

        $cancel = $this->service->processToggle(35, $this->togglePayload(3, 0, 3, $day), 2, '職員');
        $this->assertNotEmpty($cancel);
        $row = TableRegistry::getTableLocator()->get('TIndividualReservationInfo')->find()
            ->where(['i_id_user' => 3, 'i_id_room' => 35, 'i_reservation_type' => 3])->first();
        $this->assertSame(0, (int)$row->eat_flag, '取消されている');

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('対象ユーザーは指定された部屋に所属していません。');
        $this->service->processToggle(35, $this->togglePayload(3, 1, 3, 21), 2, '職員');
    }

    /** 直前編集の期間（今日〜14日後）は、職員は自分の予約を取消せない */
    public function testProcessToggle_staffCannotCancelOnLastMinuteBoundaryDay14(): void
    {
        $this->addRoomMembership(2, 38, 0, 0);
        $this->reservationTableInsert(2, 38, date('Y-m-d', strtotime('+14 days')), 3);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('職員は直前編集でのキャンセルはできません。');
        $this->service->processToggle(38, $this->togglePayload(2, 0, 3, 14), 2, '職員');
    }

    /** 職員レベル 7 も、職員(0)と同じく直前編集の期間は自分の予約を取消せない */
    public function testProcessToggle_level7StaffCannotCancelOnLastMinuteDay14(): void
    {
        ConnectionManager::get('test')->update('m_user_info', ['i_user_level' => 7], ['i_id_user' => 2]);
        $this->addRoomMembership(2, 40, 0, 0);
        $this->reservationTableInsert(2, 40, date('Y-m-d', strtotime('+14 days')), 3);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('職員は直前編集でのキャンセルはできません。');
        $this->service->processToggle(40, $this->togglePayload(2, 0, 3, 14), 2, '職員');
    }

    /** 15日後は直前編集の期間外なので、職員も通常どおり取消せる */
    public function testProcessToggle_staffCanCancelOutsideLastMinuteWindowDay15(): void
    {
        $this->addRoomMembership(2, 39, 0, 0);
        $this->reservationTableInsert(2, 39, date('Y-m-d', strtotime('+15 days')), 3);

        $this->service->processToggle(39, $this->togglePayload(2, 0, 3, 15), 2, '職員');

        $this->assertSame(0, $this->eatFlag(2, 39, 3), '取消が反映されている');
    }

    /** 他人の予約を扱うには、非管理者は操作者本人も部屋に有効所属している必要がある */
    public function testProcessToggle_actorNotMember_cannotEditChild(): void
    {
        $this->addRoomMembership(3, 36, 0, 0);   // 児童のみ有効所属。職員(user 2)は未所属

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('この部屋を操作する権限がありません。');
        $this->service->processToggle(36, $this->togglePayload(3, 1), 2, '職員');
    }

    /** 管理者は、所属外の部屋で他人の予約を取消せる（対象が退出済みでも） */
    public function testProcessToggle_adminCanCancelForNotMemberTarget(): void
    {
        $this->addRoomMembership(3, 37, 1, 0);   // 児童は退出済み。管理者(user 1)は未所属
        $this->reservationTableInsert(3, 37, date('Y-m-d', strtotime('+20 days')), 3);

        $this->service->processToggle(37, $this->togglePayload(3, 0), 1, '管理者');

        $this->assertSame(0, $this->eatFlag(3, 37, 3), '管理者の取消が反映されている');
    }

    /** 管理者でも、削除済み・存在しない部屋には登録も取消もできない */
    public function testProcessToggle_adminCannotTouchDeletedOrMissingRoom(): void
    {
        $this->addRoomMembership(3, 56, 0, 1);   // 削除済みの部屋
        $this->reservationTableInsert(3, 56, date('Y-m-d', strtotime('+20 days')), 3);

        foreach ([[56, 0], [56, 1], [9999, 0]] as [$room, $value]) {
            try {
                $this->service->processToggle($room, $this->togglePayload(3, $value), 1, '管理者');
                $this->fail("管理者でも拒否される: room={$room} value={$value}");
            } catch (UnauthorizedException $e) {
                $this->assertSame('選択された部屋は利用できません。', $e->getMessage());
            }
        }
        $this->assertSame(1, $this->eatFlag(3, 56, 3), '削除済みの部屋の予約は変更されない');
    }

    /** 行が無い取消（value=0）では、所属外・削除済みの部屋に無効値の行を作らない */
    public function testProcessToggle_cancelWithoutExistingRow_doesNotCreateRowInNotMemberRoom(): void
    {
        $this->addRoomMembership(1, 54, 1, 0);   // 管理者(user 1)は退出済み
        $this->addRoomMembership(2, 55, 0, 1);   // 削除済みの部屋

        // 管理者本人が退出済みの部屋(54)も、対象(user 3)が未所属なので「行が無い取消」は拒否される
        foreach ([54 => '対象ユーザーは指定された部屋に所属していません。', 55 => '選択された部屋は利用できません。'] as $room => $message) {
            try {
                $this->service->processToggle($room, $this->togglePayload(3, 0), 1, '管理者');
                $this->fail('行が無い取消は拒否される');
            } catch (UnauthorizedException $e) {
                $this->assertSame($message, $e->getMessage());
            }
            $this->assertNull($this->eatFlag(3, $room, 3), '行が作られていない');
        }
    }

    private function eatFlag(int $userId, int $roomId, int $meal): ?int
    {
        $row = TableRegistry::getTableLocator()->get('TIndividualReservationInfo')->find()
            ->where(['i_id_user' => $userId, 'i_id_room' => $roomId, 'i_reservation_type' => $meal])->first();

        return $row === null ? null : (int)$row->eat_flag;
    }

    /** グループ予約: 取消のみなら、対象が退出済みでも通る */
    public function testProcessGroupReservation_cancelOnly_allowedForLeftTarget(): void
    {
        $this->addRoomMembership(2, 38, 0, 0);
        $this->addMember(3, 38, 1);
        $this->reservationTableInsert(3, 38, date('Y-m-d', strtotime('+20 days')), 1);

        $result = $this->service->processGroupReservation(
            date('Y-m-d', strtotime('+20 days')),
            ['i_id_room' => 38, 'users' => [3 => [1 => 0]]],
            [38 => '部屋38'],
            '職員',
            fn() => true,
            2,
            false,
            0,
            false
        );

        $this->assertNotEmpty($result);
        $this->assertSame(0, $this->eatFlag(3, 38, 1), '退出済みの児童の予約が取消されている');
    }

    /** グループ予約: 操作者が部屋に所属していないと、対象が有効所属でも書けない */
    public function testProcessGroupReservation_actorNotMember_isRejected(): void
    {
        $this->addRoomMembership(3, 39, 0, 0);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('この部屋を操作する権限がありません。');
        $this->service->processGroupReservation(
            date('Y-m-d', strtotime('+20 days')),
            ['i_id_room' => 39, 'users' => [3 => [1 => 1]]],
            [39 => '部屋39'],
            '職員',
            fn() => true,
            2,
            false,
            0,
            false
        );
    }

    private function reservationTableInsert(int $userId, int $roomId, string $date, int $meal): void
    {
        ConnectionManager::get('test')->insert('t_individual_reservation_info', [
            'i_id_user' => $userId, 'd_reservation_date' => $date, 'i_reservation_type' => $meal, 'i_id_room' => $roomId,
            'eat_flag' => 1, 'i_change_flag' => 1, 'i_version' => 1,
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't', 'dt_update' => '2026-01-01 00:00:00', 'c_update_user' => 't',
        ]);
    }

    // ----------------------------------------------------------------
    // processBulkToggle（エクセル食数予約の一括登録）
    // ----------------------------------------------------------------

    public function testProcessBulkToggle_emptyItemsReturnsEmpty(): void
    {
        $this->assertSame([], $this->service->processBulkToggle([], 1, '管理者ユーザー'));
    }

    public function testProcessBulkToggle_returnsResultPerItemInOrder(): void
    {
        // いずれも過去日で失敗するが、item 単位で結果が順序どおり返ることを確認する。
        $items = [
            ['roomId' => 1, 'userId' => 1, 'date' => '2020-01-01', 'meal' => 1, 'value' => 1],
            ['roomId' => 1, 'userId' => 1, 'date' => '2020-01-02', 'meal' => 2, 'value' => 1],
        ];

        $results = $this->service->processBulkToggle($items, 1, '管理者ユーザー');

        $this->assertCount(2, $results);
        $this->assertFalse($results[0]['ok']);
        $this->assertFalse($results[1]['ok']);
        $this->assertStringContainsString('過去日', $results[0]['message']);
    }

    public function testProcessBulkToggle_oneFailureDoesNotStopOthers(): void
    {
        // 1件目は過去日エラー、2件目は存在しないユーザー。どちらも独立して失敗結果になり、
        // 片方の失敗が全体を止めない（一部成功・一部失敗の許容）ことを確認する。
        $future = date('Y-m-d', strtotime('+20 days'));
        $items = [
            ['roomId' => 1, 'userId' => 1,     'date' => '2020-01-01', 'meal' => 1, 'value' => 1],
            ['roomId' => 1, 'userId' => 99999, 'date' => $future,      'meal' => 1, 'value' => 1],
        ];

        $results = $this->service->processBulkToggle($items, 1, '管理者ユーザー');

        $this->assertCount(2, $results);
        $this->assertFalse($results[0]['ok']);
        $this->assertFalse($results[1]['ok']);
    }

    public function testProcessBulkToggle_successForValidFutureItem(): void
    {
        // 管理者(user1)が自分の所属部屋(room1)へ未来日の予約を登録 → 成功。
        $future = date('Y-m-d', strtotime('+20 days'));
        $items = [
            ['roomId' => 1, 'userId' => 1, 'date' => $future, 'meal' => 1, 'value' => 1],
        ];

        $results = $this->service->processBulkToggle($items, 1, '管理者ユーザー');

        $this->assertCount(1, $results);
        $this->assertTrue($results[0]['ok'], $results[0]['message'] ?? '');
    }

    public function testProcessBulkToggle_userIdOmittedFallsBackToLoginUser(): void
    {
        // userId を省略した item は「自分の予約」として扱われ、ログインユーザー(user1)で成功する。
        // （processToggle が userId 未指定時に loginUserId を使う挙動に揃っていることの確認）
        $future = date('Y-m-d', strtotime('+21 days'));
        $items = [
            ['roomId' => 1, 'date' => $future, 'meal' => 2, 'value' => 1],
        ];

        $results = $this->service->processBulkToggle($items, 1, '管理者ユーザー');

        $this->assertCount(1, $results);
        $this->assertTrue($results[0]['ok'], $results[0]['message'] ?? '');
    }

    public function testProcessBulkToggle_systemErrorHidesSqlAndShowsErrorCode(): void
    {
        // 1件目: DB エラー（デッドロック）、2件目: DB 接続エラー、3件目: 通常のエラー（過去日）。
        $service = new class (
            TableRegistry::getTableLocator()->get('TIndividualReservationInfo'),
            TableRegistry::getTableLocator()->get('MUserInfo'),
            TableRegistry::getTableLocator()->get('MRoomInfo'),
            '/webroot/'
        ) extends ReservationWriteService {
            public function processToggle(int $roomId, array $payload, int $loginUserId, string $loginUserName, bool $invalidateCache = true): array
            {
                return match ((int)$payload['meal']) {
                    1 => throw new \Cake\Database\Exception\QueryException(
                        'UPDATE t_individual_reservation_info SET i_change_flag = 1',
                        new \PDOException('SQLSTATE[40001]: Serialization failure: 1213 Deadlock found when trying to get lock')
                    ),
                    2 => throw new \PDOException('SQLSTATE[HY000] [2002] Connection refused'),
                    default => parent::processToggle($roomId, $payload, $loginUserId, $loginUserName, $invalidateCache),
                };
            }
        };
        $items = [
            ['roomId' => 1, 'userId' => 1, 'date' => '2099-01-01', 'meal' => 1, 'value' => 1],
            ['roomId' => 1, 'userId' => 1, 'date' => '2099-01-01', 'meal' => 2, 'value' => 1],
            ['roomId' => 1, 'userId' => 1, 'date' => '2020-01-01', 'meal' => 3, 'value' => 1],
        ];

        $results = $service->processBulkToggle($items, 1, '管理者ユーザー');

        $this->assertSame(
            '予約の更新に失敗しました（他の操作と同時に更新されたため。もう一度お試しください [エラーコード: DB-1213]）',
            $results[0]['message']
        );
        $this->assertSame(
            '予約の更新に失敗しました（システムエラーのため。管理者にお問い合わせください [エラーコード: DB-2002]）',
            $results[1]['message']
        );
        foreach ([$results[0]['message'], $results[1]['message']] as $msg) {
            $this->assertStringNotContainsString('SQLSTATE', $msg);
            $this->assertStringNotContainsString('t_individual_reservation_info', $msg);
            $this->assertStringNotContainsString('データベース', $msg);
        }
        // 通常のエラーはこれまでどおり具体的な理由が出る（エラーコードは付かない）。
        $this->assertStringContainsString('過去日', $results[2]['message']);
        $this->assertStringNotContainsString('エラーコード', $results[2]['message']);
    }

    // ----------------------------------------------------------------
    // processRoomChange: 旧部屋の取消と新部屋の登録を1トランザクションで行う
    // ----------------------------------------------------------------

    /** @return array<int, int> [roomId => eat_flag] */
    private function eatFlagsByRoom(int $userId, string $date, int $meal): array
    {
        $rows = TableRegistry::getTableLocator()->get('TIndividualReservationInfo')->find()
            ->where(['i_id_user' => $userId, 'd_reservation_date' => $date, 'i_reservation_type' => $meal])
            ->all();
        $map = [];
        foreach ($rows as $r) {
            $map[(int)$r->i_id_room] = (int)$r->eat_flag;
        }

        return $map;
    }

    public function testProcessRoomChange_movesReservation(): void
    {
        $this->addRoomMembership(1, 40, 0, 0);
        $this->addRoomMembership(1, 41, 0, 0);
        $date = date('Y-m-d', strtotime('+20 days'));
        $this->reservationTableInsert(1, 40, $date, 3);

        $this->service->processRoomChange(40, 41, ['date' => $date, 'meal' => 3, 'userId' => 1], 1, '管理者ユーザー');

        $this->assertSame([40 => 0, 41 => 1], $this->eatFlagsByRoom(1, $date, 3));
    }

    public function testProcessRoomChange_rollsBackWhenDestinationRejected(): void
    {
        $this->addRoomMembership(1, 42, 0, 0);
        $this->addRoomMembership(1, 43, 1, 0); // 移動先は退出済み
        $date = date('Y-m-d', strtotime('+20 days'));
        $this->reservationTableInsert(1, 42, $date, 3);

        try {
            $this->service->processRoomChange(42, 43, ['date' => $date, 'meal' => 3, 'userId' => 1], 1, '管理者ユーザー');
            $this->fail('UnauthorizedException が出るはず');
        } catch (UnauthorizedException $e) {
            $this->assertSame([42 => 1], $this->eatFlagsByRoom(1, $date, 3), '旧部屋の予約が残っている');
        }
    }

    public function testProcessRoomChange_rejectsWhenSourceHasNoReservation(): void
    {
        $this->addRoomMembership(1, 44, 0, 0);
        $this->addRoomMembership(1, 45, 0, 0);
        $date = date('Y-m-d', strtotime('+20 days'));

        try {
            $this->service->processRoomChange(44, 45, ['date' => $date, 'meal' => 3, 'userId' => 1], 1, '管理者ユーザー');
            $this->fail('ConflictException が出るはず');
        } catch (ConflictException $e) {
            $this->assertSame([], $this->eatFlagsByRoom(1, $date, 3), '移動先に予約が作られていない');
        }
    }

    public function testProcessToggle_rejectsSameMealAlreadyReservedInAnotherRoom(): void
    {
        $this->addRoomMembership(1, 46, 0, 0);
        $this->addRoomMembership(1, 47, 0, 0);
        $date = date('Y-m-d', strtotime('+20 days'));
        $this->reservationTableInsert(1, 46, $date, 3);

        try {
            $this->service->processToggle(47, ['date' => $date, 'meal' => 3, 'value' => 1, 'userId' => 1], 1, '管理者ユーザー');
            $this->fail('DuplicateMealConflictException が出るはず');
        } catch (DuplicateMealConflictException $e) {
            $this->assertStringContainsString('の夕食は、別の部屋ですでに予約されています', $e->getMessage());
            $this->assertSame([46 => 1], $this->eatFlagsByRoom(1, $date, 3));
        }
    }

    /** 部屋の移動（新しい部屋のON → 古い部屋のOFF の順）でも両方成功し、結果は送った順番で返る */
    public function testProcessBulkToggle_onBeforeOffForRoomMove_bothSucceedInSentOrder(): void
    {
        $this->addRoomMembership(1, 48, 0, 0); // 部屋A
        $this->addRoomMembership(1, 49, 0, 0); // 部屋B
        $date = date('Y-m-d', strtotime('+20 days'));
        $this->reservationTableInsert(1, 48, $date, 3);

        $results = $this->service->processBulkToggle([
            ['roomId' => 49, 'userId' => 1, 'date' => $date, 'meal' => 3, 'value' => 1],
            ['roomId' => 48, 'userId' => 1, 'date' => $date, 'meal' => 3, 'value' => 0],
        ], 1, '管理者ユーザー');

        $this->assertSame([0, 1], array_keys($results), '結果が送った順番になっていない');
        $this->assertTrue($results[0]['ok'], $results[0]['message'] ?? '');
        $this->assertTrue($results[0]['value'], 'キー0は部屋BのONの結果のはず');
        $this->assertTrue($results[1]['ok'], $results[1]['message'] ?? '');
        $this->assertFalse($results[1]['value'], 'キー1は部屋AのOFFの結果のはず');
        $this->assertSame([48 => 0, 49 => 1], $this->eatFlagsByRoom(1, $date, 3));
    }

    /** 移動元に取消済み行（0/0）しかないときは部屋変更を拒否し、移動先に行を作らない */
    public function testProcessRoomChange_rejectsWhenSourceHasOnlyCancelledRow(): void
    {
        $this->addRoomMembership(1, 50, 0, 0);
        $this->addRoomMembership(1, 51, 0, 0);
        $date = date('Y-m-d', strtotime('+20 days'));
        $this->reservationTableInsert(1, 50, $date, 3);
        TableRegistry::getTableLocator()->get('TIndividualReservationInfo')->updateAll(
            ['eat_flag' => 0, 'i_change_flag' => 0],
            ['i_id_user' => 1, 'i_id_room' => 50, 'd_reservation_date' => $date, 'i_reservation_type' => 3]
        );

        try {
            $this->service->processRoomChange(50, 51, ['date' => $date, 'meal' => 3, 'userId' => 1], 1, '管理者ユーザー');
            $this->fail('ConflictException が出るはず');
        } catch (ConflictException $e) {
            $this->assertSame([50 => 0], $this->eatFlagsByRoom(1, $date, 3), '移動先に予約が作られている');
        }
    }

    public function testProcessToggle_rejectsReactivatingCancelledRowWhileAnotherRoomIsEffective(): void
    {
        // 部屋変更の後に残る取消済み行（既存行）を ON にする経路。新規保存ではなく更新を通る
        $this->addRoomMembership(1, 48, 0, 0);
        $this->addRoomMembership(1, 49, 0, 0);
        $date = date('Y-m-d', strtotime('+20 days'));
        $this->reservationTableInsert(1, 49, $date, 3);
        ConnectionManager::get('test')->insert('t_individual_reservation_info', [
            'i_id_user' => 1, 'd_reservation_date' => $date, 'i_reservation_type' => 3, 'i_id_room' => 48,
            'eat_flag' => 0, 'i_change_flag' => 0, 'i_version' => 1,
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't', 'dt_update' => '2026-01-01 00:00:00', 'c_update_user' => 't',
        ]);

        try {
            $this->service->processToggle(48, ['date' => $date, 'meal' => 3, 'value' => 1, 'userId' => 1], 1, '管理者ユーザー');
            $this->fail('DuplicateMealConflictException が出るはず');
        } catch (DuplicateMealConflictException $e) {
            $this->assertSame([48 => 0, 49 => 1], $this->eatFlagsByRoom(1, $date, 3));
        }
    }
}
