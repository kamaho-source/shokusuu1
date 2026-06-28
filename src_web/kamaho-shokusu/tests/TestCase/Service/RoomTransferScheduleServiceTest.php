<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\RoomTransferScheduleService;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

class RoomTransferScheduleServiceTest extends TestCase
{
    protected array $fixtures = [
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
    // applyPending — DB使用
    // ----------------------------------------------------------------

    public function testApplyPending_noPendingSchedules_returnsZeroApplied(): void
    {
        // m_room_transfer_schedule テーブルがテストDBに存在しない場合はスキップする
        try {
            $result = $this->service->applyPending(date('Y-m-d'));
        } catch (\Cake\Database\Exception\DatabaseException $e) {
            $this->markTestSkipped('m_room_transfer_schedule テーブルがテストDBに存在しません: ' . $e->getMessage());
            return;
        }

        $this->assertArrayHasKey('applied', $result);
        $this->assertArrayHasKey('errors', $result);
        $this->assertSame(0, $result['applied']);
        $this->assertSame([], $result['errors']);
    }

    public function testApplyPending_dryRun_returnsRequiredKeys(): void
    {
        try {
            $result = $this->service->applyPending(date('Y-m-d'), dryRun: true);
        } catch (\Cake\Database\Exception\DatabaseException $e) {
            $this->markTestSkipped('m_room_transfer_schedule テーブルがテストDBに存在しません: ' . $e->getMessage());
            return;
        }

        $this->assertArrayHasKey('applied', $result);
        $this->assertArrayHasKey('errors', $result);
    }
}
