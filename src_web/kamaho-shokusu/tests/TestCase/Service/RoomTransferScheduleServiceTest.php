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
}
