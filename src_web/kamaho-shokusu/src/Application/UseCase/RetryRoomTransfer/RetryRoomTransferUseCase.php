<?php
declare(strict_types=1);

namespace App\Application\UseCase\RetryRoomTransfer;

use App\Domain\Entity\RoomTransferSchedule;
use App\Domain\Exception\NotFoundException;
use App\Domain\Repository\RoomTransferScheduleRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * 適用失敗の部屋異動を予約中に戻し、次のバッチで適用し直す（#945）。
 * 管理者が失敗の理由（昼食と弁当の重なりなど）を直してから使う。理由は消える。
 */
final class RetryRoomTransferUseCase
{
    public function __construct(
        private readonly RoomTransferScheduleRepositoryInterface $schedules,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @throws NotFoundException 予約が無い場合
     * @throws \Throwable DB の保存の失敗（変換せずにそのまま投げる）
     */
    public function execute(RetryRoomTransferInput $input): RetryRoomTransferOutput
    {
        $schedule = $this->schedules->find($input->scheduleId);
        if ($schedule === null) {
            throw new NotFoundException('指定された予約が見つかりません。');
        }
        if (!$schedule->isFailed()) {
            return RetryRoomTransferOutput::rejected('やり直せるのは適用失敗のスケジュールのみです。');
        }
        $changed = $this->schedules->changeStatus($input->scheduleId, RoomTransferSchedule::STATUS_PENDING, $input->actorName, $this->clock->now()->format('Y-m-d H:i:s'), [RoomTransferSchedule::STATUS_FAILED]);
        if (!$changed) {
            return RetryRoomTransferOutput::rejected('ほかの操作で状態が変わりました。画面を読み込み直してください。');
        }

        return RetryRoomTransferOutput::retried();
    }
}
