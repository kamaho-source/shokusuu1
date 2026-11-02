<?php
declare(strict_types=1);

namespace App\Application\UseCase\CancelRoomTransfer;

use App\Domain\Entity\RoomTransferSchedule;
use App\Domain\Exception\NotFoundException;
use App\Domain\Repository\RoomTransferScheduleRepositoryInterface;
use Psr\Clock\ClockInterface;

/**
 * 部屋異動の予約を取り消す。取り消せるのは予約中のものだけ。
 */
final class CancelRoomTransferUseCase
{
    public function __construct(
        private readonly RoomTransferScheduleRepositoryInterface $schedules,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @throws NotFoundException 予約が無い場合
     * @throws \Throwable DB の保存の失敗（変換せずにそのまま投げる）
     */
    public function execute(CancelRoomTransferInput $input): CancelRoomTransferOutput
    {
        $scheduleId = $input->scheduleId;
        $schedule = $this->schedules->find($scheduleId);
        if ($schedule === null) {
            throw new NotFoundException('指定された予約が見つかりません。');
        }
        if (!$schedule->isPending()) {
            return CancelRoomTransferOutput::rejected('キャンセルできるのは予約中のスケジュールのみです。');
        }
        $this->schedules->changeStatus($scheduleId, RoomTransferSchedule::STATUS_CANCELLED, $input->actorName, $this->clock->now()->format('Y-m-d H:i:s'));

        return CancelRoomTransferOutput::cancelled();
    }
}
