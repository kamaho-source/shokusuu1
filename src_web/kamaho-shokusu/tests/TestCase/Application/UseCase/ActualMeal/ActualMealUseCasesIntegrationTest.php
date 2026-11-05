<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\ActualMeal;

use App\Application\UseCase\RequestActualMealApproval\RequestActualMealApprovalInput;
use App\Application\UseCase\RequestActualMealApproval\RequestActualMealApprovalUseCase;
use App\Application\UseCase\SaveActualMeal\SaveActualMealInput;
use App\Application\UseCase\SaveActualMeal\SaveActualMealOutput;
use App\Application\UseCase\SaveActualMeal\SaveActualMealUseCase;
use App\Domain\ValueObject\ApprovalKey;
use App\Infrastructure\Log\AuditLogAdapter;
use App\Infrastructure\Query\CakeRoomQuery;
use App\Infrastructure\Repository\CakeActualMealRepository;
use Cake\Chronos\ClockFactory;
use Cake\Datasource\ConnectionManager;
use Cake\ORM\Table;
use Cake\TestSuite\TestCase;

/**
 * 実食の保存（SaveActualMeal）と承認申請（RequestActualMealApproval）を実 DB で確かめる。
 * 旧 ActualMealManagementServiceTest の saveActualMeal・requestApproval を引き継ぐ（期待値は同じ）。
 * 部屋の所属の確認が UseCase に入ったので、対象者 101 を部屋 1 の所属にしておく。
 */
class ActualMealUseCasesIntegrationTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TIndividualReservationInfo',
        'app.TAuditLog',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        ConnectionManager::get('test')->insert('m_user_group', [
            'i_id_user' => 101, 'i_id_room' => 1, 'active_flag' => 0,
            'dt_create' => '2024-01-01 00:00:00', 'dt_update' => '2024-01-01 00:00:00',
        ]);
    }

    private function save(int $userId, int $roomId, string $date, int $mealType, bool $checked, int $expectedVersion, string $actor, string $loginId = ''): SaveActualMealOutput
    {
        $useCase = new SaveActualMealUseCase(new CakeActualMealRepository(), new CakeRoomQuery(), new AuditLogAdapter(), new ClockFactory('Asia/Tokyo'));

        return $useCase->execute(new SaveActualMealInput(new ApprovalKey($userId, $date, $roomId, $mealType), $checked, $expectedVersion, $actor, $loginId));
    }

    /**
     * @param list<array{user_id: int, room_id: int, date: string, meal_type: int}> $keys
     */
    private function requestApproval(array $keys, string $actor, string $loginId = ''): int
    {
        $useCase = new RequestActualMealApprovalUseCase(new CakeActualMealRepository(), new AuditLogAdapter(), new ClockFactory('Asia/Tokyo'));
        $list = array_map(static fn(array $k): ApprovalKey => new ApprovalKey($k['user_id'], $k['date'], $k['room_id'], $k['meal_type']), $keys);

        return $useCase->execute(new RequestActualMealApprovalInput($list, $actor, $loginId))->count;
    }

    private function insertReservation(int $userId, string $date, int $type, int $eat, int $change, int $status = 0, int $version = 1, int $roomId = 1): void
    {
        ConnectionManager::get('test')->insert('t_individual_reservation_info', [
            'i_id_user' => $userId, 'd_reservation_date' => $date, 'i_reservation_type' => $type,
            'i_id_room' => $roomId, 'eat_flag' => $eat, 'i_change_flag' => $change,
            'i_approval_status' => $status, 'i_version' => $version,
            'dt_create' => '2024-01-01 00:00:00', 'c_create_user' => 't',
            'dt_update' => '2024-01-01 00:00:00', 'c_update_user' => 't',
        ]);
    }

    /**
     * @return array<string, string|int|null>
     */
    private function rowOf(int $userId, string $date, int $type): array
    {
        $row = ConnectionManager::get('test')->execute(
            'SELECT * FROM t_individual_reservation_info WHERE i_id_user = ? AND d_reservation_date = ? AND i_reservation_type = ?',
            [$userId, $date, $type]
        )->fetch('assoc');
        $this->assertIsArray($row);

        return $row;
    }

    /** 保存（新規作成）で save が例外を投げる・false を返す表に差し替える */
    private function useFailingTable(string $mode): void
    {
        $table = $this->getMockBuilder(Table::class)
            ->onlyMethods(['save'])
            ->setConstructorArgs([['table' => 't_individual_reservation_info', 'alias' => 'TIndividualReservationInfo']])
            ->getMock();
        if ($mode === 'throw') {
            $table->method('save')->willThrowException(new \RuntimeException('duplicate'));
        }
        if ($mode === 'returnFalse') {
            $table->method('save')->willReturn(false);
        }
        $this->getTableLocator()->set('TIndividualReservationInfo', $table);
    }

    /**
     * 実食保存の監査ログ（actual_meal_save）を古い順に返す。
     *
     * @return list<array<string, string|int|null>>
     */
    private function actualMealSaveLogs(): array
    {
        return ConnectionManager::get('test')->execute(
            "SELECT * FROM t_audit_log WHERE c_action = 'actual_meal_save' ORDER BY i_id_audit"
        )->fetchAll('assoc');
    }

    // ---- SaveActualMeal ----

    public function testSaveActualMeal_invalidMealType_returnsError(): void
    {
        $result = $this->save(1, 1, '2099-01-01', 4, true, 1, 'tester'); // 4 は無効 (1,2,3 のみ有効)

        $this->assertSame(SaveActualMealOutput::REJECTED, $result->status);
        $this->assertStringContainsString('無効な食事タイプ', $result->message);
    }

    public function testSaveActualMeal_createsNewRecordWhenMissing(): void
    {
        $result = $this->save(101, 1, '2000-01-03', 1, true, 1, 'tester');

        $this->assertSame(SaveActualMealOutput::SAVED, $result->status);
        $this->assertSame(1, $result->version);
        $row = $this->rowOf(101, '2000-01-03', 1);
        $this->assertSame(1, (int)$row['eat_flag']);
        $this->assertSame(1, (int)$row['i_change_flag']);
        $this->assertSame(0, (int)$row['i_approval_status']);
    }

    public function testSaveActualMeal_updatesExistingWithOptimisticLock(): void
    {
        $this->insertReservation(101, '2000-01-03', 2, 1, 1, 0, 4);

        $result = $this->save(101, 1, '2000-01-03', 2, false, 4, 'tester', 'login1');

        $this->assertSame(SaveActualMealOutput::SAVED, $result->status);
        $this->assertSame(5, $result->version);
        $row = $this->rowOf(101, '2000-01-03', 2);
        $this->assertSame(0, (int)$row['i_change_flag']);
        $this->assertSame(0, (int)$row['eat_flag']);
        $this->assertSame(5, (int)$row['i_version']);
        $this->assertSame('tester', $row['c_update_user']);
    }

    public function testSaveActualMeal_staleVersion_isRejectedWithoutChange(): void
    {
        $this->insertReservation(101, '2000-01-03', 2, 1, 1, 0, 4);

        $result = $this->save(101, 1, '2000-01-03', 2, false, 3, 'tester');

        $this->assertSame(SaveActualMealOutput::REJECTED, $result->status);
        $this->assertStringContainsString('他のユーザーによって更新', $result->message);
        $this->assertSame(1, (int)$this->rowOf(101, '2000-01-03', 2)['i_change_flag']);
    }

    public function testSaveActualMeal_approvedRecord_cannotBeChanged(): void
    {
        foreach ([1, 2] as $i => $status) {
            $this->insertReservation(101, '2000-01-0' . (3 + $i), 1, 1, 1, $status);
            $result = $this->save(101, 1, '2000-01-0' . (3 + $i), 1, false, 1, 'tester');

            $this->assertSame(SaveActualMealOutput::REJECTED, $result->status);
            $this->assertStringContainsString('承認済み', $result->message);
            $this->assertSame(1, (int)$this->rowOf(101, '2000-01-0' . (3 + $i), 1)['i_change_flag']);
        }
    }

    public function testSaveActualMeal_saveThrows_returnsReloadMessage(): void
    {
        $this->useFailingTable('throw');

        $result = $this->save(101, 1, '2000-01-03', 1, true, 1, 'tester');

        $this->assertSame(SaveActualMealOutput::REJECTED, $result->status);
        $this->assertStringContainsString('再読み込み', $result->message);
    }

    public function testSaveActualMeal_saveReturnsFalse_returnsFailure(): void
    {
        $this->useFailingTable('returnFalse');

        $result = $this->save(101, 1, '2000-01-03', 1, true, 1, 'tester');

        $this->assertSame(SaveActualMealOutput::REJECTED, $result->status);
        $this->assertSame('保存に失敗しました。', $result->message);
        $this->assertNull($result->version);
    }

    public function testSaveActualMeal_create_recordsOneAuditLog(): void
    {
        $result = $this->save(101, 1, '2000-01-03', 1, true, 1, 'tester', 'login1');

        $this->assertSame(SaveActualMealOutput::SAVED, $result->status);
        $logs = $this->actualMealSaveLogs();
        $this->assertCount(1, $logs);
        $this->assertSame('actual_meal', $logs[0]['c_category']);
        $this->assertSame('t_individual_reservation_info', $logs[0]['c_target_table']);
        $this->assertSame('101:2000-01-03:1:1', $logs[0]['c_target_id']);
        $this->assertSame(1, (int)$logs[0]['i_result']);
        $this->assertSame('login1', $logs[0]['c_actor_login_id']);
        $this->assertSame(
            ['checked' => true, 'meal_type' => 1, 'created' => true],
            json_decode((string)$logs[0]['c_detail'], true)
        );
    }

    public function testSaveActualMeal_update_recordsAuditLogAsBefore(): void
    {
        $this->insertReservation(101, '2000-01-03', 2, 1, 1, 0, 4);

        $this->save(101, 1, '2000-01-03', 2, false, 4, 'tester', 'login1');

        $logs = $this->actualMealSaveLogs();
        $this->assertCount(1, $logs);
        $this->assertSame('101:2000-01-03:1:2', $logs[0]['c_target_id']);
        $this->assertSame(['checked' => false, 'meal_type' => 2], json_decode((string)$logs[0]['c_detail'], true));
    }

    public function testSaveActualMeal_createFails_recordsNoAuditLog(): void
    {
        foreach (['returnFalse', 'throw'] as $mode) {
            $this->useFailingTable($mode);

            $result = $this->save(101, 1, '2000-01-03', 1, true, 1, 'tester');

            $this->assertSame(SaveActualMealOutput::REJECTED, $result->status, $mode);
        }
        $this->assertSame([], $this->actualMealSaveLogs());
    }

    public function testSaveActualMeal_staleVersion_recordsNoAuditLog(): void
    {
        $this->insertReservation(101, '2000-01-03', 2, 1, 1, 0, 4);

        $this->save(101, 1, '2000-01-03', 2, false, 3, 'tester');

        $this->assertSame([], $this->actualMealSaveLogs());
    }

    /** 部屋の確認（以前はコントローラー）。使えない部屋は 403、所属していなければ既にある予約の更新だけ */
    public function testSaveActualMeal_roomChecks(): void
    {
        $unknownRoom = $this->save(101, 9999, '2000-01-03', 1, true, 1, 'tester');
        $this->assertSame(SaveActualMealOutput::FORBIDDEN, $unknownRoom->status);
        $this->assertSame('選択された部屋は利用できません。', $unknownRoom->message);

        $notMember = $this->save(102, 1, '2000-01-03', 1, true, 1, 'tester');
        $this->assertSame(SaveActualMealOutput::FORBIDDEN, $notMember->status);
        $this->assertSame('対象ユーザーは指定された部屋に所属していません。', $notMember->message);

        $this->insertReservation(102, '2000-01-03', 1, 0, 0);
        $this->assertSame(SaveActualMealOutput::SAVED, $this->save(102, 1, '2000-01-03', 1, true, 1, 'tester')->status);
        $this->assertCount(1, $this->actualMealSaveLogs());
    }

    // ---- RequestActualMealApproval ----

    public function testRequestApproval_emptyKeys_returnsZero(): void
    {
        $this->assertSame(0, $this->requestApproval([], 'tester'));
    }

    public function testRequestApproval_resetsOnlyPendingOrRejected(): void
    {
        $this->insertReservation(101, '2000-01-03', 1, 1, 1, 3); // 差し戻し
        $this->insertReservation(101, '2000-01-03', 2, 1, 1, 1); // 承認済み
        $this->insertReservation(101, '2000-01-03', 3, 1, 1, 0); // 未承認
        $keys = [
            ['user_id' => 101, 'room_id' => 1, 'date' => '2000-01-03', 'meal_type' => 1],
            ['user_id' => 101, 'room_id' => 1, 'date' => '2000-01-03', 'meal_type' => 2],
            ['user_id' => 101, 'room_id' => 1, 'date' => '2000-01-03', 'meal_type' => 3],
        ];

        $affected = $this->requestApproval($keys, 'tester', 'login1');

        $this->assertSame(2, $affected);
        $this->assertSame(0, (int)$this->rowOf(101, '2000-01-03', 1)['i_approval_status']);
        $this->assertSame(1, (int)$this->rowOf(101, '2000-01-03', 2)['i_approval_status']);
    }

    public function testRequestApproval_noMatchingRows_returnsZero(): void
    {
        $keys = [['user_id' => 999, 'room_id' => 1, 'date' => '2000-01-03', 'meal_type' => 1]];

        $this->assertSame(0, $this->requestApproval($keys, 'tester'));
    }
}
