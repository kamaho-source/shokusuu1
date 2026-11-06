<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\RetryRoomTransfer;

use App\Application\UseCase\RetryRoomTransfer\RetryRoomTransferInput;
use App\Application\UseCase\RetryRoomTransfer\RetryRoomTransferOutput;
use App\Application\UseCase\RetryRoomTransfer\RetryRoomTransferUseCase;
use App\Domain\Entity\RoomTransferSchedule;
use App\Domain\Exception\NotFoundException;
use App\Test\TestCase\Application\Fake\FakeRoomTransferScheduleRepository;
use Cake\Chronos\Chronos;
use Cake\Chronos\ClockFactory;
use PHPUnit\Framework\TestCase;

/**
 * 適用失敗の異動を、予約中に戻して次のバッチで適用し直す（#945）。今日は 2026-11-20（Asia/Tokyo）に固定する。
 */
class RetryRoomTransferUseCaseTest extends TestCase
{
    private FakeRoomTransferScheduleRepository $schedules;

    protected function setUp(): void
    {
        parent::setUp();
        Chronos::setTestNow(new Chronos('2026-11-20 09:00:00', 'Asia/Tokyo'));
        $this->schedules = new FakeRoomTransferScheduleRepository();
        $this->schedules->schedules[1] = new RoomTransferSchedule(1, 20, 1, 2, '2026-11-10', RoomTransferSchedule::STATUS_FAILED, '昼と弁当が重なります。');
        $this->schedules->schedules[2] = new RoomTransferSchedule(2, 20, 1, 2, '2026-11-10', RoomTransferSchedule::STATUS_PENDING);
    }

    protected function tearDown(): void
    {
        Chronos::setTestNow(null);
        parent::tearDown();
    }

    private function retry(int $id): RetryRoomTransferOutput
    {
        return (new RetryRoomTransferUseCase($this->schedules, new ClockFactory('Asia/Tokyo')))->execute(new RetryRoomTransferInput($id, '管理者'));
    }

    /** 有効開始日が過去なら今日にそろえて予約中に戻す（過去の予約・食数を動かさないため） */
    public function testFailedWithPastDate_backToPendingFromToday(): void
    {
        $out = $this->retry(1);

        $this->assertSame(RetryRoomTransferOutput::RETRIED, $out->status);
        $this->assertSame('2026-11-20', $out->movedTo);
        $this->assertSame([[1, '2026-11-20', '管理者']], $this->schedules->reopens);
    }

    /** 有効開始日が今日以降なら、その日のまま予約中に戻す */
    public function testFailedWithFutureDate_keepsTheDate(): void
    {
        $this->schedules->schedules[3] = new RoomTransferSchedule(3, 20, 1, 2, '2026-12-01', RoomTransferSchedule::STATUS_FAILED, '理由');

        $out = $this->retry(3);

        $this->assertNull($out->movedTo);
        $this->assertSame([[3, '2026-12-01', '管理者']], $this->schedules->reopens);
    }

    public function testNotFailed_isRejected(): void
    {
        $out = $this->retry(2);

        $this->assertSame(RetryRoomTransferOutput::REJECTED, $out->status);
        $this->assertSame('やり直せるのは適用失敗のスケジュールのみです。', $out->message);
        $this->assertSame([], $this->schedules->reopens);
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
