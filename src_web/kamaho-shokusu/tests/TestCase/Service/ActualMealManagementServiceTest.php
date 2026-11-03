<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ActualMealManagementService;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * ActualMealManagementService テスト。
 *
 * getAdminOldestAllowedMonday・getAdultUsers・buildWeekGrid・saveActualMeal・requestApproval の挙動を検証する。
 */
class ActualMealManagementServiceTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TIndividualReservationInfo',
        'app.TAuditLog',
    ];

    private ActualMealManagementService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ActualMealManagementService();
    }

    // ----------------------------------------------------------------
    // saveActualMeal — 無効な食事タイプのガード条件
    // ----------------------------------------------------------------

    public function testSaveActualMeal_invalidMealType_returnsError(): void
    {
        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');

        $result = $this->service->saveActualMeal(
            $reservationTable,
            userId: 1,
            roomId: 1,
            date: '2099-01-01',
            mealType: 4, // 4 は無効 (1,2,3 のみ有効)
            checked: true,
            expectedVersion: 1,
            actor: 'tester'
        );

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('無効な食事タイプ', $result['message']);
    }

    // ----------------------------------------------------------------
    // requestApproval — 空キーのガード条件
    // ----------------------------------------------------------------

    public function testRequestApproval_emptyKeys_returnsZero(): void
    {
        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');

        $result = $this->service->requestApproval($reservationTable, [], 'tester');

        $this->assertSame(0, $result);
    }

    // ----------------------------------------------------------------
    // 追加: 実データでの振る舞い
    // ----------------------------------------------------------------

    private function insertStaffUser(int $userId, string $name, ?string $staffId, int $roomId, int $activeFlag = 0, int $delFlag = 0): void
    {
        $db = \Cake\Datasource\ConnectionManager::get('test');
        $db->insert('m_user_info', [
            'i_id_user' => $userId, 'c_login_account' => 'acct' . $userId, 'c_login_passwd' => 'x',
            'c_user_name' => $name, 'i_admin' => 0, 'i_user_level' => 0, 'i_enable' => 0,
            'i_del_flag' => $delFlag, 'i_id_staff' => $staffId,
            'dt_create' => '2024-01-01 00:00:00', 'dt_update' => '2024-01-01 00:00:00',
        ]);
        $db->insert('m_user_group', [
            'i_id_user' => $userId, 'i_id_room' => $roomId, 'active_flag' => $activeFlag,
            'dt_create' => '2024-01-01 00:00:00', 'dt_update' => '2024-01-01 00:00:00',
        ]);
    }

    private function insertReservation(int $userId, string $date, int $type, int $eat, int $change, int $status = 0, int $version = 1, int $roomId = 1): void
    {
        \Cake\Datasource\ConnectionManager::get('test')->insert('t_individual_reservation_info', [
            'i_id_user' => $userId, 'd_reservation_date' => $date, 'i_reservation_type' => $type,
            'i_id_room' => $roomId, 'eat_flag' => $eat, 'i_change_flag' => $change,
            'i_approval_status' => $status, 'i_version' => $version,
            'dt_create' => '2024-01-01 00:00:00', 'c_create_user' => 't',
            'dt_update' => '2024-01-01 00:00:00', 'c_update_user' => 't',
        ]);
    }

    private function resTable(): \Cake\ORM\Table
    {
        return TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
    }

    private function rowOf(int $userId, string $date, int $type): array
    {
        $row = \Cake\Datasource\ConnectionManager::get('test')->execute(
            'SELECT * FROM t_individual_reservation_info WHERE i_id_user = ? AND d_reservation_date = ? AND i_reservation_type = ?',
            [$userId, $date, $type]
        )->fetch('assoc');
        $this->assertIsArray($row);

        return $row;
    }

    public function testSaveActualMeal_createsNewRecordWhenMissing(): void
    {
        $result = $this->service->saveActualMeal($this->resTable(), 101, 1, '2000-01-03', 1, true, 1, 'tester');

        $this->assertTrue($result['ok']);
        $this->assertSame(1, $result['version']);
        $row = $this->rowOf(101, '2000-01-03', 1);
        $this->assertSame(1, (int)$row['eat_flag']);
        $this->assertSame(1, (int)$row['i_change_flag']);
        $this->assertSame(0, (int)$row['i_approval_status']);
    }

    public function testSaveActualMeal_updatesExistingWithOptimisticLock(): void
    {
        $this->insertReservation(101, '2000-01-03', 2, 1, 1, 0, 4);

        $result = $this->service->saveActualMeal($this->resTable(), 101, 1, '2000-01-03', 2, false, 4, 'tester', 'login1');

        $this->assertTrue($result['ok']);
        $this->assertSame(5, $result['version']);
        $row = $this->rowOf(101, '2000-01-03', 2);
        $this->assertSame(0, (int)$row['i_change_flag']);
        $this->assertSame(0, (int)$row['eat_flag']);
        $this->assertSame(5, (int)$row['i_version']);
        $this->assertSame('tester', $row['c_update_user']);
    }

    public function testSaveActualMeal_staleVersion_isRejectedWithoutChange(): void
    {
        $this->insertReservation(101, '2000-01-03', 2, 1, 1, 0, 4);

        $result = $this->service->saveActualMeal($this->resTable(), 101, 1, '2000-01-03', 2, false, 3, 'tester');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('他のユーザーによって更新', $result['message']);
        $this->assertSame(1, (int)$this->rowOf(101, '2000-01-03', 2)['i_change_flag']);
    }

    public function testSaveActualMeal_approvedRecord_cannotBeChanged(): void
    {
        foreach ([1, 2] as $i => $status) {
            $this->insertReservation(101, '2000-01-0' . (3 + $i), 1, 1, 1, $status);
            $result = $this->service->saveActualMeal($this->resTable(), 101, 1, '2000-01-0' . (3 + $i), 1, false, 1, 'tester');

            $this->assertFalse($result['ok']);
            $this->assertStringContainsString('承認済み', $result['message']);
            $this->assertSame(1, (int)$this->rowOf(101, '2000-01-0' . (3 + $i), 1)['i_change_flag']);
        }
    }

    public function testSaveActualMeal_saveThrows_returnsReloadMessage(): void
    {
        $table = $this->getMockBuilder(\Cake\ORM\Table::class)
            ->onlyMethods(['save'])
            ->setConstructorArgs([['table' => 't_individual_reservation_info', 'alias' => 'TIndividualReservationInfo']])
            ->getMock();
        $table->method('save')->willThrowException(new \RuntimeException('duplicate'));

        $result = $this->service->saveActualMeal($table, 101, 1, '2000-01-03', 1, true, 1, 'tester');

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('再読み込み', $result['message']);
    }

    public function testSaveActualMeal_saveReturnsFalse_returnsFailure(): void
    {
        $table = $this->getMockBuilder(\Cake\ORM\Table::class)
            ->onlyMethods(['save'])
            ->setConstructorArgs([['table' => 't_individual_reservation_info', 'alias' => 'TIndividualReservationInfo']])
            ->getMock();
        $table->method('save')->willReturn(false);

        $result = $this->service->saveActualMeal($table, 101, 1, '2000-01-03', 1, true, 1, 'tester');

        $this->assertSame(['ok' => false, 'message' => '保存に失敗しました。'], $result);
    }

    /**
     * 実食保存の監査ログ（actual_meal_save）を古い順に返す。
     *
     * @return list<array<string, string|int|null>>
     */
    private function actualMealSaveLogs(): array
    {
        return \Cake\Datasource\ConnectionManager::get('test')->execute(
            "SELECT * FROM t_audit_log WHERE c_action = 'actual_meal_save' ORDER BY i_id_audit"
        )->fetchAll('assoc');
    }

    public function testSaveActualMeal_create_recordsOneAuditLog(): void
    {
        $result = $this->service->saveActualMeal($this->resTable(), 101, 1, '2000-01-03', 1, true, 1, 'tester', 'login1');

        $this->assertTrue($result['ok']);
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

        $this->service->saveActualMeal($this->resTable(), 101, 1, '2000-01-03', 2, false, 4, 'tester', 'login1');

        $logs = $this->actualMealSaveLogs();
        $this->assertCount(1, $logs);
        $this->assertSame('101:2000-01-03:1:2', $logs[0]['c_target_id']);
        $this->assertSame(['checked' => false, 'meal_type' => 2], json_decode((string)$logs[0]['c_detail'], true));
    }

    public function testSaveActualMeal_createFails_recordsNoAuditLog(): void
    {
        foreach (['returnFalse', 'throw'] as $mode) {
            $table = $this->getMockBuilder(\Cake\ORM\Table::class)
                ->onlyMethods(['save'])
                ->setConstructorArgs([['table' => 't_individual_reservation_info', 'alias' => 'TIndividualReservationInfo']])
                ->getMock();
            if ($mode === 'throw') {
                $table->method('save')->willThrowException(new \RuntimeException('duplicate'));
            }
            if ($mode === 'returnFalse') {
                $table->method('save')->willReturn(false);
            }

            $result = $this->service->saveActualMeal($table, 101, 1, '2000-01-03', 1, true, 1, 'tester');

            $this->assertFalse($result['ok'], $mode);
        }
        $this->assertSame([], $this->actualMealSaveLogs());
    }

    public function testSaveActualMeal_staleVersion_recordsNoAuditLog(): void
    {
        $this->insertReservation(101, '2000-01-03', 2, 1, 1, 0, 4);

        $this->service->saveActualMeal($this->resTable(), 101, 1, '2000-01-03', 2, false, 3, 'tester');

        $this->assertSame([], $this->actualMealSaveLogs());
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

        $affected = $this->service->requestApproval($this->resTable(), $keys, 'tester', 'login1');

        $this->assertSame(2, $affected);
        $this->assertSame(0, (int)$this->rowOf(101, '2000-01-03', 1)['i_approval_status']);
        $this->assertSame(1, (int)$this->rowOf(101, '2000-01-03', 2)['i_approval_status']);
    }

    public function testRequestApproval_noMatchingRows_returnsZero(): void
    {
        $keys = [['user_id' => 999, 'room_id' => 1, 'date' => '2000-01-03', 'meal_type' => 1]];

        $this->assertSame(0, $this->service->requestApproval($this->resTable(), $keys, 'tester'));
    }
}
