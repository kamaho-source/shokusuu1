<?php
declare(strict_types=1);

namespace App\Application\UseCase\ApplyRoomTransfers;

use App\Application\Port\ReservationCacheInterface;
use App\Application\Port\TransactionManagerInterface;
use App\Domain\Entity\RoomTransferSchedule;
use App\Domain\Repository\ReservationRepositoryInterface;
use App\Domain\Repository\RoomTransferScheduleRepositoryInterface;
use App\Domain\Repository\UserAccountRepositoryInterface;
use App\Domain\Service\RoomTransferReservationRule;
use Cake\Chronos\ChronosDate;
use Psr\Clock\ClockInterface;

/**
 * 有効開始日が来た部屋異動の予約を、1件ずつ1つのトランザクションで適用する（毎日のバッチ）。
 *
 * 1. 所属を切り替える（異動元を退出済みに、異動先を所属中に）。
 * 2. 異動元の部屋の、有効開始日以降の予約を異動先へ移し、部屋の集計を増減する（RoomTransferReservationRule）。
 * 3. 予約を適用済みにする。
 * 4. キャッシュを捨てる。
 *
 * 1件が失敗しても、その1件だけ取り消して次へ進む。削除済みの人の予約は適用せず、予約中のまま残す（復元されれば次の適用で異動する）。
 */
final class ApplyRoomTransfersUseCase
{
    public function __construct(
        private readonly RoomTransferScheduleRepositoryInterface $schedules,
        private readonly UserAccountRepositoryInterface $users,
        private readonly ReservationRepositoryInterface $reservations,
        private readonly TransactionManagerInterface $transaction,
        private readonly ReservationCacheInterface $cache,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @param string $date 基準日（'Y-m-d'）。有効開始日がこの日以前の予約を適用する
     * @param bool $dryRun true なら適用せずに取り消す（件数だけ数える）
     */
    public function execute(string $date, bool $dryRun = false): ApplyRoomTransfersOutput
    {
        $applied = 0;
        $errors  = [];
        foreach ($this->schedules->pendingUntil($date) as $schedule) {
            if ($this->users->find($schedule->userId)?->isDeleted()) {
                continue;
            }
            $this->transaction->begin();
            try {
                $this->apply($schedule);
                if ($dryRun) {
                    $this->transaction->rollback();
                } else {
                    $this->transaction->commit();
                    $this->cache->forgetAfterRoomTransfer($date, $schedule->userId);
                }
                $applied++;
            } catch (\Throwable $e) {
                if ($this->transaction->inTransaction()) {
                    $this->transaction->rollback();
                }
                $errors[] = sprintf('スケジュールID=%d: %s', $schedule->id, $e->getMessage());
            }
        }

        return new ApplyRoomTransfersOutput($applied, $errors);
    }

    private function apply(RoomTransferSchedule $schedule): void
    {
        $now   = $this->clock->now();
        $at    = $now->format('Y-m-d H:i:s');
        $today = new ChronosDate($now);

        $this->users->transferRoom($schedule->userId, $schedule->roomFromId, $schedule->roomToId, $at);
        if ($schedule->roomFromId !== null) {
            foreach ($this->reservations->findFromDate($schedule->userId, $schedule->roomFromId, $schedule->effectiveDate) as $source) {
                $destination = $this->reservations->findOne($source->userId, $schedule->roomToId, $source->date, $source->mealType);
                $plan = RoomTransferReservationRule::plan($source, $destination, $today);
                $this->reservations->moveForTransfer($source, $schedule->roomToId, $plan['overwriteDestination'], $plan['createDestination'], $at);
                $this->reservations->adjustRoomCount($source->date, $schedule->roomFromId, $source->mealType, $plan['fromDelta'], $at);
                $this->reservations->adjustRoomCount($source->date, $schedule->roomToId, $source->mealType, $plan['toDelta'], $at);
            }
        }
        $this->schedules->changeStatus($schedule->id, RoomTransferSchedule::STATUS_APPLIED, 'batch', $at);
    }
}
