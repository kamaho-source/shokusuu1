<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\RetryRoomTransfer;

use App\Application\UseCase\RetryRoomTransfer\RetryRoomTransferInput;
use App\Application\UseCase\RetryRoomTransfer\RetryRoomTransferOutput;
use App\Application\UseCase\RetryRoomTransfer\RetryRoomTransferUseCase;
use App\Domain\Entity\RoomTransferSchedule;
use App\Domain\Exception\NotFoundException;
use App\Test\TestCase\Application\Fake\FakeRoomTransferScheduleRepository;
use Cake\Chronos\ClockFactory;
use PHPUnit\Framework\TestCase;

/**
 * 適用失敗の異動を、予約中に戻して次のバッチで適用し直す（#945）。
 */
class RetryRoomTransferUseCaseTest extends TestCase
{
    private FakeRoomTransferScheduleRepository $schedules;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schedules = new FakeRoomTransferScheduleRepository();
        $this->schedules->schedules[1] = new RoomTransferSchedule(1, 20, 1, 2, '2026-11-10', RoomTransferSchedule::STATUS_FAILED, '昼と弁当が重なります。');
        $this->schedules->schedules[2] = new RoomTransferSchedule(2, 20, 1, 2, '2026-11-10', RoomTransferSchedule::STATUS_PENDING);
    }

    private function retry(int $id): RetryRoomTransferOutput
    {
        return (new RetryRoomTransferUseCase($this->schedules, new ClockFactory('Asia/Tokyo')))->execute(new RetryRoomTransferInput($id, '管理者'));
    }

    public function testFailed_backToPending(): void
    {
        $out = $this->retry(1);

        $this->assertSame(RetryRoomTransferOutput::RETRIED, $out->status);
        $this->assertSame([[1, RoomTransferSchedule::STATUS_PENDING, '管理者']], $this->schedules->statusChanges);
    }

    public function testNotFailed_isRejected(): void
    {
        $out = $this->retry(2);

        $this->assertSame(RetryRoomTransferOutput::REJECTED, $out->status);
        $this->assertSame('やり直せるのは適用失敗のスケジュールのみです。', $out->message);
        $this->assertSame([], $this->schedules->statusChanges);
    }

    public function testMissing_throwsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->retry(999);
    }

    public function testChangedAfterRead_isRejected(): void
    {
        $this->schedules->currentStatus[1] = RoomTransferSchedule::STATUS_CANCELLED;

        $out = $this->retry(1);

        $this->assertSame(RetryRoomTransferOutput::REJECTED, $out->status);
        $this->assertSame('ほかの操作で状態が変わりました。画面を読み込み直してください。', $out->message);
    }
}
