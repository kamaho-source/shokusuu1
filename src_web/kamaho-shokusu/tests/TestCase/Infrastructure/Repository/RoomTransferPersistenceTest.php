<?php
declare(strict_types=1);

namespace App\Test\TestCase\Infrastructure\Repository;

use App\Domain\Entity\MealReservation;
use App\Domain\Entity\RoomTransferSchedule;
use App\Infrastructure\Repository\CakeReservationRepository;
use App\Infrastructure\Repository\CakeRoomTransferScheduleRepository;
use App\Infrastructure\Repository\CakeUserAccountRepository;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * 部屋異動の適用で使う Repository の書き込み（予約の移し替え・部屋の集計・所属の切り替え・予約中の一覧）。
 */
class RoomTransferPersistenceTest extends TestCase
{
    protected array $fixtures = [
        'app.MRoomTransferSchedule',
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TIndividualReservationInfo',
        'app.TReservationInfo',
    ];

    private const DATE = '2026-12-01';
    private const AT = '2026-11-02 09:00:00';

    private function reservations(): CakeReservationRepository
    {
        return new CakeReservationRepository($this->getTableLocator()->get('TIndividualReservationInfo'));
    }

    /**
     * @param array<string, mixed> $override
     */
    private function insert(int $roomId, array $override = []): void
    {
        ConnectionManager::get('test')->insert('t_individual_reservation_info', $override + [
            'i_id_user' => 1, 'd_reservation_date' => self::DATE, 'i_reservation_type' => 1, 'i_id_room' => $roomId,
            'eat_flag' => 1, 'i_change_flag' => 1, 'i_approval_status' => 0, 'i_version' => 1,
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 'creator',
        ]);
    }

    /** @return array<string, mixed> */
    private function row(int $roomId): array
    {
        return ConnectionManager::get('test')->execute(
            'SELECT eat_flag, i_change_flag, i_approval_status, i_version, c_create_user, dt_create, c_update_user FROM t_individual_reservation_info WHERE i_id_user = 1 AND i_id_room = ? AND d_reservation_date = ?',
            [$roomId, self::DATE]
        )->fetch('assoc') ?: [];
    }

    private function source(): MealReservation
    {
        return new MealReservation(1, self::DATE, 1, 1, 1, 1, 3);
    }

    public function testCreateDestination_copiesCreatorAndApproval_andRemovesSource(): void
    {
        $this->insert(1, ['eat_flag' => null, 'i_approval_status' => 2, 'i_version' => 3]);

        $this->reservations()->moveForTransfer($this->source(), 2, false, true, self::AT);

        $this->assertSame([], $this->row(1), '異動元の行は消える');
        $moved = $this->row(2);
        $this->assertNotNull($moved['eat_flag'], '当初予定の NULL は 0 にする（従来どおり）');
        $this->assertSame([0, 1, 2, 1, 'creator', '2026-01-01 00:00:00', 'batch'], [
            (int)$moved['eat_flag'], (int)$moved['i_change_flag'], (int)$moved['i_approval_status'], (int)$moved['i_version'],
            $moved['c_create_user'], (string)$moved['dt_create'], $moved['c_update_user'],
        ], '当初予定の NULL は 0、作成者・作成日時・承認状態は引き継ぐ。版は従来どおり 1 から');
    }

    public function testOverwriteDestination_copiesFlagsAndApproval_andBumpsVersion(): void
    {
        $this->insert(1, ['i_approval_status' => 1, 'i_version' => 3]);
        $this->insert(2, ['eat_flag' => 0, 'i_change_flag' => 0, 'i_version' => 5, 'c_create_user' => 'dest']);

        $this->reservations()->moveForTransfer($this->source(), 2, true, false, self::AT);

        $this->assertSame([], $this->row(1));
        $dest = $this->row(2);
        $this->assertSame([1, 1, 1, 6, 'dest', 'batch'], [
            (int)$dest['eat_flag'], (int)$dest['i_change_flag'], (int)$dest['i_approval_status'], (int)$dest['i_version'],
            $dest['c_create_user'], $dest['c_update_user'],
        ], '版は大きい方 + 1。作成者は異動先のまま');
    }

    public function testKeepDestination_onlyRemovesSource(): void
    {
        $this->insert(1);
        $this->insert(2, ['i_version' => 4, 'c_create_user' => 'dest']);

        $this->reservations()->moveForTransfer($this->source(), 2, false, false, self::AT);

        $this->assertSame([], $this->row(1));
        $this->assertSame([4, null], [(int)$this->row(2)['i_version'], $this->row(2)['c_update_user']], '異動先の行は変えない');
    }

    public function testAdjustRoomCount_neverBelowZero_andCreatesRowOnlyWhenIncreasing(): void
    {
        $repo = $this->reservations();
        $db = ConnectionManager::get('test');
        $count = fn(int $roomId) => $db->execute('SELECT i_taberu_ninzuu FROM t_reservation_info WHERE d_reservation_date = ? AND i_id_room = ? AND c_reservation_type = 1', [self::DATE, $roomId])->fetchColumn(0);

        $repo->adjustRoomCount(self::DATE, 1, 1, -1, self::AT);
        $this->assertFalse($count(1), '行が無いまま減らしても作らない');
        $repo->adjustRoomCount(self::DATE, 1, 1, 1, self::AT);
        $this->assertSame(1, (int)$count(1));
        $repo->adjustRoomCount(self::DATE, 1, 1, -1, self::AT);
        $repo->adjustRoomCount(self::DATE, 1, 1, -1, self::AT);
        $this->assertSame(0, (int)$count(1), '0 未満にはしない');
    }

    public function testTransferRoom_leavesSource_andReactivatesExistingDestination(): void
    {
        $db = ConnectionManager::get('test');
        $db->insert('m_user_group', ['i_id_user' => 1, 'i_id_room' => 2, 'active_flag' => 1, 'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 't']);

        (new CakeUserAccountRepository($this->getTableLocator()->get('MUserInfo')))->transferRoom(1, 1, 2, self::AT);

        $flags = $db->execute('SELECT i_id_room, active_flag FROM m_user_group WHERE i_id_user = 1 ORDER BY i_id_room')->fetchAll('assoc');
        $this->assertSame([[1, 1], [2, 0]], array_map(fn($r) => [(int)$r['i_id_room'], (int)$r['active_flag']], $flags));
    }

    public function testPendingUntil_onlyPendingSchedulesUpToTheDate(): void
    {
        $db = ConnectionManager::get('test');
        $db->execute('DELETE FROM m_room_transfer_schedule');
        foreach ([[0, '2026-11-01'], [1, '2026-11-01'], [2, '2026-11-01'], [0, '2026-11-03']] as [$status, $date]) {
            $db->insert('m_room_transfer_schedule', ['i_id_user' => 1, 'i_id_room_from' => 1, 'i_id_room_to' => 2, 'd_effective_date' => $date, 'i_status' => $status, 'dt_create' => self::AT, 'c_create_user' => 't']);
        }

        $pending = (new CakeRoomTransferScheduleRepository())->pendingUntil('2026-11-02');

        $this->assertCount(1, $pending);
        $this->assertSame(RoomTransferSchedule::STATUS_PENDING, $pending[0]->status);
        $this->assertSame('2026-11-01', $pending[0]->effectiveDate);
    }

    /** 適用失敗にすると理由が残り、予約中に戻すと理由を消す（#945） */
    public function testMarkFailed_keepsReason_andChangeStatusClearsIt(): void
    {
        $db = ConnectionManager::get('test');
        $db->execute('DELETE FROM m_room_transfer_schedule');
        $db->insert('m_room_transfer_schedule', ['i_id_user' => 1, 'i_id_room_from' => 1, 'i_id_room_to' => 2, 'd_effective_date' => '2026-11-01', 'i_status' => 0, 'dt_create' => self::AT, 'c_create_user' => 't']);
        $repo = new CakeRoomTransferScheduleRepository();
        $id = $repo->pendingUntil('2026-11-02')[0]->id;

        $repo->markFailed($id, str_repeat('あ', 300), self::AT);
        $failed = $repo->find($id);
        $this->assertSame(RoomTransferSchedule::STATUS_FAILED, $failed?->status);
        $this->assertSame(255, mb_strlen((string)$failed->failReason), '長い理由は列の長さに切る');
        $this->assertSame([], $repo->pendingUntil('2026-11-02'));

        $repo->changeStatus($id, RoomTransferSchedule::STATUS_PENDING, '管理者', self::AT);
        $this->assertNull($repo->find($id)?->failReason);
    }

    /** 状態を変える書き込みは、思っている状態のときだけ行う（ほかの操作の結果を上書きしない） */
    public function testConditionalWrites_doNotOverwriteOtherChanges(): void
    {
        $db = ConnectionManager::get('test');
        $db->execute('DELETE FROM m_room_transfer_schedule');
        $db->insert('m_room_transfer_schedule', ['i_id_user' => 1, 'i_id_room_from' => 1, 'i_id_room_to' => 2, 'd_effective_date' => '2026-11-01', 'i_status' => RoomTransferSchedule::STATUS_CANCELLED, 'dt_create' => self::AT, 'c_create_user' => 't']);
        $id = (int)$db->execute('SELECT MAX(i_id) FROM m_room_transfer_schedule')->fetchColumn(0);
        $repo = new CakeRoomTransferScheduleRepository();

        $this->assertFalse($repo->markFailed($id, '理由', self::AT), '取消済みを適用失敗にしない');
        $this->assertFalse($repo->changeStatus($id, RoomTransferSchedule::STATUS_APPLIED, 'batch', self::AT, [RoomTransferSchedule::STATUS_PENDING]));
        $this->assertSame([RoomTransferSchedule::STATUS_CANCELLED, null], [$repo->find($id)?->status, $repo->find($id)?->failReason]);

        $this->assertTrue($repo->changeStatus($id, RoomTransferSchedule::STATUS_PENDING, '管理者', self::AT, [RoomTransferSchedule::STATUS_CANCELLED]));
        $this->assertTrue($repo->markFailed($id, '理由', self::AT));
        $this->assertSame(RoomTransferSchedule::STATUS_FAILED, $repo->find($id)?->status);
    }
}
