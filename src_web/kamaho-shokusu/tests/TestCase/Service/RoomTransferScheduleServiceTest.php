<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\RoomTransferScheduleService;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\Date;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * RoomTransferScheduleService テスト。
 *
 * applyPending() が保留スケジュールのない状態で正しく動作することを検証する。
 */
class RoomTransferScheduleServiceTest extends TestCase
{
    protected array $fixtures = [
        'app.MRoomTransferSchedule',
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TIndividualReservationInfo',
        'app.TReservationInfo',
    ];

    private RoomTransferScheduleService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RoomTransferScheduleService();
    }

    // ----------------------------------------------------------------
    // applyPending — 保留スケジュールなし
    // ----------------------------------------------------------------

    public function testApplyPending_noPendingSchedules_returnsZeroApplied(): void
    {
        $result = $this->service->applyPending(date('Y-m-d'));

        $this->assertArrayHasKey('applied', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertSame(0, $result['applied']);
        $this->assertSame([], $result['errors']);
    }

    public function testApplyPending_dryRun_returnsRequiredKeys(): void
    {
        $result = $this->service->applyPending(date('Y-m-d'), dryRun: true);

        $this->assertArrayHasKey('applied', $result);
        $this->assertArrayHasKey('errors', $result);
    }

    public function testApplyPending_returnsAppliedAsInteger(): void
    {
        $result = $this->service->applyPending(date('Y-m-d'));

        $this->assertIsInt($result['applied']);
    }

    public function testApplyPending_returnsErrorsAsArray(): void
    {
        $result = $this->service->applyPending(date('Y-m-d'));

        $this->assertIsArray($result['errors']);
    }

    // ----------------------------------------------------------------
    // 個人予約の移行（バグ6: 承認状態の引き継ぎ）
    // ----------------------------------------------------------------

    /**
     * バグ6: 部屋異動で予約を移行しても承認状態がリセットされない。
     *
     * 承認状態が失われると、未承認扱いの行が厨房向け集計へ即時加算されて
     * 承認ワークフローと集計が食い違うため、異動元の値を引き継ぐ。
     */
    public function testApplyPending_migratedReservationKeepsApprovalStatus(): void
    {
        $today  = Date::today('Asia/Tokyo')->format('Y-m-d');
        $future = Date::today('Asia/Tokyo')->addDays(20)->format('Y-m-d');
        $conn   = ConnectionManager::get('test');

        // user 1 は部屋1に所属（MUserGroup フィクスチャ）し、部屋2へ異動する
        $conn->insert('t_individual_reservation_info', [
            'i_id_user'          => 1,
            'd_reservation_date' => $future,
            'i_reservation_type' => 1,
            'i_id_room'          => 1,
            'eat_flag'           => 1,
            'i_change_flag'      => 1,
            'i_approval_status'  => 2, // 管理者承認済み
            'i_version'          => 1,
            'dt_create'          => '2026-01-01 00:00:00',
            'c_create_user'      => 'test',
        ]);
        $conn->insert('m_room_transfer_schedule', [
            'i_id_user'        => 1,
            'i_id_room_from'   => 1,
            'i_id_room_to'     => 2,
            'd_effective_date' => $today,
            'i_status'         => 0, // PENDING
            'dt_create'        => '2026-01-01 00:00:00',
            'c_create_user'    => 'test',
        ]);

        $result = $this->service->applyPending($today);

        $this->assertSame(1, $result['applied'], implode(' / ', $result['errors']));

        $migrated = TableRegistry::getTableLocator()->get('TIndividualReservationInfo')->find()
            ->where([
                'i_id_user'          => 1,
                'i_id_room'          => 2,
                'd_reservation_date' => $future,
                'i_reservation_type' => 1,
            ])
            ->first();

        $this->assertNotNull($migrated, '異動先に予約が移行されていない');
        $this->assertSame(2, (int)$migrated->i_approval_status, '異動で承認状態がリセットされている');
        $this->assertSame(1, (int)$migrated->eat_flag);
    }

    // ----------------------------------------------------------------
    // 個人予約の移行 — 二重予約防止との組み合わせ
    // ----------------------------------------------------------------

    private function insertReservation(int $roomId, string $date, int $eat, int $chg): void
    {
        ConnectionManager::get('test')->insert('t_individual_reservation_info', [
            'i_id_user'          => 1,
            'd_reservation_date' => $date,
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

    private function scheduleTransfer(string $today): void
    {
        // user 1 を部屋1 → 部屋2 へ異動
        ConnectionManager::get('test')->insert('m_room_transfer_schedule', [
            'i_id_user'        => 1,
            'i_id_room_from'   => 1,
            'i_id_room_to'     => 2,
            'd_effective_date' => $today,
            'i_status'         => 0,
            'dt_create'        => '2026-01-01 00:00:00',
            'c_create_user'    => 'test',
        ]);
    }

    /** @return array<int, int> [roomId => eat_flag] */
    private function eatFlagsByRoom(string $date): array
    {
        $rows = TableRegistry::getTableLocator()->get('TIndividualReservationInfo')->find()
            ->where(['i_id_user' => 1, 'd_reservation_date' => $date, 'i_reservation_type' => 1])
            ->orderByAsc('i_id_room')
            ->all();
        $map = [];
        foreach ($rows as $r) {
            $map[(int)$r->i_id_room] = (int)$r->eat_flag;
        }

        return $map;
    }

    /** 移行先に取消済み行があっても、移行元の有効な予約が引き継がれて消えない */
    public function testApplyPending_cancelledRowInDestination_takesOverEffectiveReservation(): void
    {
        $today  = Date::today('Asia/Tokyo')->format('Y-m-d');
        $future = Date::today('Asia/Tokyo')->addDays(20)->format('Y-m-d');
        $this->insertReservation(1, $future, 1, 1);
        $this->insertReservation(2, $future, 0, 0);
        $this->scheduleTransfer($today);

        $result = $this->service->applyPending($today);

        $this->assertSame(1, $result['applied'], implode(' / ', $result['errors']));
        $this->assertSame([2 => 1], $this->eatFlagsByRoom($future), '異動で予約が消えている');
    }

    /** 第三の部屋に以前からの二重予約が残っていても、異動は止まらない */
    public function testApplyPending_existingDuplicateInThirdRoom_doesNotStopTransfer(): void
    {
        $today  = Date::today('Asia/Tokyo')->format('Y-m-d');
        $future = Date::today('Asia/Tokyo')->addDays(20)->format('Y-m-d');
        $this->insertReservation(1, $future, 1, 1);
        $this->insertReservation(3, $future, 1, 1);
        $this->scheduleTransfer($today);

        $result = $this->service->applyPending($today);

        $this->assertSame(1, $result['applied'], implode(' / ', $result['errors']));
        $this->assertSame([2 => 1, 3 => 1], $this->eatFlagsByRoom($future));
    }

    /** 移行先に有効な行があっても、移行元の行は消えるので、移行元の部屋の人数を1減らす */
    public function testApplyPending_destinationAlreadyEffective_decrementsSourceCount(): void
    {
        $today  = Date::today('Asia/Tokyo')->format('Y-m-d');
        $future = Date::today('Asia/Tokyo')->addDays(20)->format('Y-m-d');
        $this->insertReservation(1, $future, 1, 1);
        $this->insertReservation(2, $future, 1, 1);
        $db = ConnectionManager::get('test');
        foreach ([1, 2] as $roomId) {
            $db->insert('t_reservation_info', [
                'd_reservation_date' => $future, 'i_id_room' => $roomId, 'c_reservation_type' => 1,
                'i_taberu_ninzuu' => 1, 'i_tabenai_ninzuu' => 0,
                'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 'test',
            ]);
        }
        $this->scheduleTransfer($today);

        $result = $this->service->applyPending($today);

        $this->assertSame(1, $result['applied'], implode(' / ', $result['errors']));
        $counts = [];
        foreach (TableRegistry::getTableLocator()->get('TReservationInfo')->find()
            ->where(['d_reservation_date' => $future, 'c_reservation_type' => 1])->all() as $r) {
            $counts[(int)$r->i_id_room] = (int)$r->i_taberu_ninzuu;
        }
        $this->assertSame(0, $counts[1] ?? null, '移行元の人数が減っていない');
        $this->assertSame(1, $counts[2] ?? null);
    }

    /** 直前期間に追加した予約（eat0/chg1）も、異動で人数（t_reservation_info）を移行元から移行先へ移す */
    public function testApplyPending_lastMinuteAddedRow_movesTheCount(): void
    {
        $today = Date::today('Asia/Tokyo')->format('Y-m-d');
        $near  = Date::today('Asia/Tokyo')->addDays(5)->format('Y-m-d');
        $this->insertReservation(1, $near, 0, 1);
        ConnectionManager::get('test')->insert('t_reservation_info', [
            'd_reservation_date' => $near, 'i_id_room' => 1, 'c_reservation_type' => 1,
            'i_taberu_ninzuu' => 1, 'i_tabenai_ninzuu' => 0,
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 'test',
        ]);
        $this->scheduleTransfer($today);

        $result = $this->service->applyPending($today);

        $this->assertSame(1, $result['applied'], implode(' / ', $result['errors']));
        $counts = [];
        foreach (TableRegistry::getTableLocator()->get('TReservationInfo')->find()
            ->where(['d_reservation_date' => $near, 'c_reservation_type' => 1])->all() as $r) {
            $counts[(int)$r->i_id_room] = (int)$r->i_taberu_ninzuu;
        }
        $this->assertSame([1 => 0, 2 => 1], $counts);
    }
}
