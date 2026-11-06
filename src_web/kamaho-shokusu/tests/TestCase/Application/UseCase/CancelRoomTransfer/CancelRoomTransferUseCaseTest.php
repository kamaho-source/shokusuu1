<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\CancelRoomTransfer;

use App\Application\UseCase\CancelRoomTransfer\CancelRoomTransferInput;
use App\Application\UseCase\CancelRoomTransfer\CancelRoomTransferOutput;
use App\Application\UseCase\CancelRoomTransfer\CancelRoomTransferUseCase;
use App\Domain\Entity\RoomTransferSchedule;
use App\Domain\Exception\NotFoundException;
use App\Test\TestCase\Application\Fake\FakeRoomTransferScheduleRepository;
use Cake\Chronos\ClockFactory;
use PHPUnit\Framework\TestCase;

/**
 * CancelRoomTransferUseCase の結果（Output の status）。
 */
class CancelRoomTransferUseCaseTest extends TestCase
{
    private FakeRoomTransferScheduleRepository $schedules;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schedules = new FakeRoomTransferScheduleRepository();
        $this->schedules->schedules[1] = new RoomTransferSchedule(1, 20, 1, 2, '2026-11-10', RoomTransferSchedule::STATUS_PENDING);
        $this->schedules->schedules[2] = new RoomTransferSchedule(2, 20, 1, 2, '2026-11-10', RoomTransferSchedule::STATUS_APPLIED);
    }

    private function cancel(int $id): CancelRoomTransferOutput
    {
        return (new CancelRoomTransferUseCase($this->schedules, new ClockFactory('Asia/Tokyo')))->execute(new CancelRoomTransferInput($id, '管理者'));
    }

    public function testPending_isCancelled(): void
    {
        $out = $this->cancel(1);

        $this->assertSame(CancelRoomTransferOutput::CANCELLED, $out->status);
        $this->assertNull($out->message);
        $this->assertSame([[1, RoomTransferSchedule::STATUS_CANCELLED, '管理者']], $this->schedules->statusChanges);
    }

    public function testNotPending_isRejectedWithReason(): void
    {
        $out = $this->cancel(2);

        $this->assertSame(CancelRoomTransferOutput::REJECTED, $out->status);
        $this->assertSame('キャンセルできるのは予約中・適用失敗のスケジュールのみです。', $out->message);
        $this->assertSame([], $this->schedules->statusChanges);
    }

    public function testMissing_throwsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->cancel(999);
    }

    /** 適用失敗の異動も取り消せる（#945） */
    public function testFailed_isCancelled(): void
    {
        $this->schedules->schedules[3] = new RoomTransferSchedule(3, 20, 1, 2, '2026-11-10', RoomTransferSchedule::STATUS_FAILED, '理由');

        $this->assertSame(CancelRoomTransferOutput::CANCELLED, $this->cancel(3)->status);
        $this->assertSame([[3, RoomTransferSchedule::STATUS_CANCELLED, '管理者']], $this->schedules->statusChanges);
    }

    /** 画面を開いたあとにバッチが適用した異動は、取り消さない（適用済みを上書きしない） */
    public function testChangedAfterRead_isRejected(): void
    {
        $this->schedules->currentStatus[1] = RoomTransferSchedule::STATUS_APPLIED;

        $out = $this->cancel(1);

        $this->assertSame(CancelRoomTransferOutput::REJECTED, $out->status);
        $this->assertSame('ほかの操作で状態が変わりました。画面を読み込み直してください。', $out->message);
        $this->assertSame([], $this->schedules->statusChanges);
    }
}
