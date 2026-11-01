<?php
declare(strict_types=1);

namespace App\Test\TestCase\Command;

use App\Infrastructure\Persistence\Table\MRoomTransferScheduleTable;
use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * ApplyRoomTransferScheduleCommand テスト
 */
class ApplyRoomTransferScheduleCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;

    protected array $fixtures = [
        'app.MRoomTransferSchedule',
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TIndividualReservationInfo',
        'app.TReservationInfo',
    ];

    /** 部屋1 → 部屋2 へ user 2 を異動させる予約を作る（部屋2も用意する） */
    private function insertPendingTransfer(string $effectiveDate): int
    {
        $conn = ConnectionManager::get('test');
        $conn->insert('m_room_info', [
            'i_id_room' => 2, 'c_room_name' => '部屋2', 'i_disp_no' => 2, 'i_enable' => 1, 'i_del_flg' => 0,
            'dt_create' => '2024-07-29 09:11:06', 'c_create_user' => 't',
        ]);
        $conn->insert('m_room_transfer_schedule', [
            'i_id_user' => 2, 'i_id_room_from' => 1, 'i_id_room_to' => 2, 'd_effective_date' => $effectiveDate,
            'i_status' => MRoomTransferScheduleTable::STATUS_PENDING, 'c_create_user' => 't', 'dt_create' => '2026-01-01 00:00:00',
        ]);

        return (int)$this->getTableLocator()->get('MRoomTransferSchedule')->find()->firstOrFail()->i_id;
    }

    private function activeRoomIdsOfUser2(): array
    {
        $rows = $this->getTableLocator()->get('MUserGroup')->find()
            ->where(['i_id_user' => 2, 'active_flag' => 0])->all();

        return array_map('intval', array_column($rows->toArray(), 'i_id_room'));
    }

    public function testExecute_noPending_reportsZero(): void
    {
        $this->exec('apply_room_transfer_schedule --date 2026-04-01');

        $this->assertExitSuccess();
        $this->assertOutputContains('基準日: 2026-04-01');
        $this->assertOutputContains('適用完了: 0件');
    }

    public function testExecute_appliesDueScheduleAndMovesUserGroup(): void
    {
        $id = $this->insertPendingTransfer('2026-04-01');

        $this->exec('apply_room_transfer_schedule --date 2026-04-01');

        $this->assertExitSuccess();
        $this->assertOutputContains('適用完了: 1件');
        $this->assertSame(MRoomTransferScheduleTable::STATUS_APPLIED, (int)$this->getTableLocator()->get('MRoomTransferSchedule')->get($id)->i_status);
        $this->assertSame([2], $this->activeRoomIdsOfUser2());
    }

    public function testExecute_notYetDue_isNotApplied(): void
    {
        $id = $this->insertPendingTransfer('2026-04-02');

        $this->exec('apply_room_transfer_schedule --date 2026-04-01');

        $this->assertExitSuccess();
        $this->assertOutputContains('適用完了: 0件');
        $this->assertSame(MRoomTransferScheduleTable::STATUS_PENDING, (int)$this->getTableLocator()->get('MRoomTransferSchedule')->get($id)->i_status);
        $this->assertSame([1], $this->activeRoomIdsOfUser2());
    }

    public function testExecute_dryRun_reportsButDoesNotCommit(): void
    {
        $id = $this->insertPendingTransfer('2026-04-01');

        $this->exec('apply_room_transfer_schedule --date 2026-04-01 --dry-run');

        $this->assertExitSuccess();
        $this->assertOutputContains('[DRY-RUN] 基準日: 2026-04-01');
        $this->assertOutputContains('[DRY-RUN] 適用予定: 1件');
        $this->assertSame(MRoomTransferScheduleTable::STATUS_PENDING, (int)$this->getTableLocator()->get('MRoomTransferSchedule')->get($id)->i_status);
        $this->assertSame([1], $this->activeRoomIdsOfUser2());
    }

    public function testExecute_scheduleFailure_returnsErrorExitAndMessage(): void
    {
        $this->insertPendingTransfer('2026-04-01');
        // 切替先の所属行を保存できなくして、スケジュール単位のエラーを発生させる
        ConnectionManager::get('test')->execute('ALTER TABLE m_user_group RENAME TO m_user_group_bak');
        try {
            $this->exec('apply_room_transfer_schedule --date 2026-04-01');
        } finally {
            ConnectionManager::get('test')->execute('ALTER TABLE m_user_group_bak RENAME TO m_user_group');
        }

        $this->assertExitError();
        $this->assertErrorContains('エラー: スケジュールID=');
        $this->assertOutputContains('適用完了: 0件');
    }
}
