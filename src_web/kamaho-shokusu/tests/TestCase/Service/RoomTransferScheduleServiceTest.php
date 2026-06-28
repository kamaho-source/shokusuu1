<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\RoomTransferScheduleService;
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
}
