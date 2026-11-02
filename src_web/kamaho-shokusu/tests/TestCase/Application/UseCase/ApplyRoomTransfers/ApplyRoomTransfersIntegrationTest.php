<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\ApplyRoomTransfers;

use App\Application\UseCase\ApplyRoomTransfers\ApplyRoomTransfersOutput;
use App\Application\UseCase\ApplyRoomTransfers\ApplyRoomTransfersUseCase;
use App\Application\UseCase\CreateRoomTransfer\CreateRoomTransferInput;
use App\Application\UseCase\CreateRoomTransfer\CreateRoomTransferUseCase;
use App\Infrastructure\Cache\ReservationCacheAdapter;
use App\Infrastructure\Persistence\CakeTransactionManager;
use App\Infrastructure\Repository\CakeReservationRepository;
use App\Infrastructure\Repository\CakeRoomTransferScheduleRepository;
use App\Infrastructure\Repository\CakeUserAccountRepository;
use Cake\Chronos\ClockFactory;
use Cake\Datasource\ConnectionManager;
use Cake\I18n\Date;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * 部屋異動の登録・適用を、本物の Repository・トランザクションにつないで確かめる（旧 RoomTransferScheduleServiceTest を引き継ぐ）。
 */
class ApplyRoomTransfersIntegrationTest extends TestCase
{
    protected array $fixtures = [
        'app.MRoomTransferSchedule',
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TIndividualReservationInfo',
        'app.TReservationInfo',
    ];

    private function apply(string $date, bool $dryRun = false): ApplyRoomTransfersOutput
    {
        $locator = TableRegistry::getTableLocator();
        $useCase = new ApplyRoomTransfersUseCase(
            new CakeRoomTransferScheduleRepository(),
            new CakeUserAccountRepository($locator->get('MUserInfo')),
            new CakeReservationRepository($locator->get('TIndividualReservationInfo')),
            new CakeTransactionManager(ConnectionManager::get('test')),
            new ReservationCacheAdapter(),
            new ClockFactory('Asia/Tokyo'),
        );

        return $useCase->execute($date, $dryRun);
    }

    private function create(string $date): ?string
    {
        return (new CreateRoomTransferUseCase(new CakeRoomTransferScheduleRepository(), new ClockFactory('Asia/Tokyo')))
            ->execute(new CreateRoomTransferInput(1, null, 1, $date, 'tester'));
    }

    // ----------------------------------------------------------------
    // applyPending — 保留スケジュールなし
    // ----------------------------------------------------------------

    public function testCreate_missingRequired_isRejected(): void
    {
        $this->assertSame('ユーザー・異動先部屋・有効開始日は必須です。', $this->create(''));
    }

    public function testCreate_invalidDate_returnsJapaneseMessage(): void
    {
        $this->assertSame('バリデーションエラー: 有効開始日は YYYY-MM-DD の正しい日付で入力してください。', $this->create('2026-13-45'));
    }

    public function testCreate_valid_savesPendingSchedule(): void
    {
        $this->assertNull($this->create('2026-12-01'));
        $row = TableRegistry::getTableLocator()->get('MRoomTransferSchedule')->find()->where(['c_create_user' => 'tester'])->firstOrFail();
        $this->assertSame(0, (int)$row->i_status);
        $this->assertNull($row->i_id_room_from);
    }

    public function testApplyPending_noPendingSchedules_returnsZeroApplied(): void
    {
        $result = $this->apply(date('Y-m-d'));

        $this->assertSame(0, $result->applied);
        $this->assertSame([], $result->errors);
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

        $result = $this->apply($today);

        $this->assertSame(1, $result->applied, implode(' / ', $result->errors));

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

        $result = $this->apply($today);

        $this->assertSame(1, $result->applied, implode(' / ', $result->errors));
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

        $result = $this->apply($today);

        $this->assertSame(1, $result->applied, implode(' / ', $result->errors));
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

        $result = $this->apply($today);

        $this->assertSame(1, $result->applied, implode(' / ', $result->errors));
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

        $result = $this->apply($today);

        $this->assertSame(1, $result->applied, implode(' / ', $result->errors));
        $counts = [];
        foreach (TableRegistry::getTableLocator()->get('TReservationInfo')->find()
            ->where(['d_reservation_date' => $near, 'c_reservation_type' => 1])->all() as $r) {
            $counts[(int)$r->i_id_room] = (int)$r->i_taberu_ninzuu;
        }
        $this->assertSame([1 => 0, 2 => 1], $counts);
    }

    /** 異動で i_change_flag の NULL を 0 にしない（当初予定のある予約を取消扱いにしない） */
    public function testApplyPending_keepsNullChangeFlag(): void
    {
        $today = Date::today('Asia/Tokyo')->format('Y-m-d');
        $near  = Date::today('Asia/Tokyo')->addDays(5)->format('Y-m-d');
        ConnectionManager::get('test')->insert('t_individual_reservation_info', [
            'i_id_user' => 1, 'd_reservation_date' => $near, 'i_reservation_type' => 1, 'i_id_room' => 1,
            'eat_flag' => 1, 'i_change_flag' => null, 'i_version' => 1,
            'dt_create' => '2026-01-01 00:00:00', 'c_create_user' => 'test',
        ]);
        $this->scheduleTransfer($today);

        $this->apply($today);

        $row = TableRegistry::getTableLocator()->get('TIndividualReservationInfo')->find()
            ->where(['i_id_user' => 1, 'i_id_room' => 2, 'd_reservation_date' => $near, 'i_reservation_type' => 1])->firstOrFail();
        $this->assertNull($row->i_change_flag);
    }

    /** 削除済みの人の部屋異動は、削除されている間は適用しない（未適用のまま残す）。#936 */
    public function testApplyPending_skipsDeletedUser_andKeepsTheSchedulePending(): void
    {
        $db = \Cake\Datasource\ConnectionManager::get('test');
        $db->insert('m_room_info', ['i_id_room' => 72, 'c_room_name' => '異動先', 'i_disp_no' => 72, 'i_del_flg' => 0]);
        $db->insert('m_room_transfer_schedule', [
            'i_id_user' => 6, 'i_id_room_from' => 1, 'i_id_room_to' => 72,
            'd_effective_date' => date('Y-m-d'), 'i_status' => 0, 'c_create_user' => 't', 'dt_create' => '2026-01-01 00:00:00',
        ]);

        $result = $this->apply(date('Y-m-d'));

        $this->assertSame(0, $result->applied);
        $this->assertSame([], $result->errors);
        $this->assertSame(0, (int)$db->execute('SELECT i_status FROM m_room_transfer_schedule WHERE i_id_user = 6')->fetchColumn(0), '未適用のまま残る');
        $this->assertSame(0, (int)$db->execute('SELECT COUNT(*) FROM m_user_group WHERE i_id_user = 6 AND i_id_room = 72')->fetchColumn(0), '削除済みの人が異動先に所属していない');
    }
}
