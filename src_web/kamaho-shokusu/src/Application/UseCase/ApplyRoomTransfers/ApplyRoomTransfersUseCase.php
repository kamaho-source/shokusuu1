<?php
declare(strict_types=1);

namespace App\Application\UseCase\ApplyRoomTransfers;

use App\Application\Port\ReservationCacheInterface;
use App\Application\Port\TransactionManagerInterface;
use App\Domain\Entity\RoomTransferSchedule;
use App\Domain\Exception\ConflictException;
use App\Domain\Exception\DomainException;
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
    /** 予期しないエラー（DB など）のとき一覧に出す理由。詳細はその日のエラー（バッチの出力）に残す */
    private const UNEXPECTED_ERROR = '予期しないエラーで適用できませんでした。バッチのログを確認してください。';

    /** バッチの途中で、管理者が取消・やり直しなどで状態を変えていたとき */
    private const CHANGED_BY_OTHER = 'ほかの操作で予約の状態が変わったため、適用しませんでした。';

    public function __construct(
        private readonly RoomTransferScheduleRepositoryInterface $schedules,
        private readonly UserAccountRepositoryInterface $users,
        private readonly ReservationRepositoryInterface $reservations,
        private readonly TransactionManagerInterface $transaction,
        private readonly ReservationCacheInterface $cache,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * 1件ごとの失敗は Output の errors に入れる（例外にしない）。
     */
    public function execute(ApplyRoomTransfersInput $input): ApplyRoomTransfersOutput
    {
        $date   = $input->date;
        $dryRun = $input->dryRun;
        $applied = 0;
        $errors  = [];
        foreach ($this->schedules->pendingUntil($date) as $schedule) {
            if ($this->users->find($schedule->userId)?->isDeleted()) {
                continue;
            }
            // 同じ部屋の異動を適用すると、その部屋の予約を消してしまう。動かさずに理由を返す（#945）
            if ($schedule->isSameRoomTransfer()) {
                $errors[] = $this->fail($schedule, RoomTransferSchedule::SAME_ROOM_MESSAGE, RoomTransferSchedule::SAME_ROOM_MESSAGE, $dryRun);
                continue;
            }
            $this->transaction->begin();
            try {
                $this->apply($schedule);
                if ($dryRun) {
                    $this->transaction->rollback();
                } else {
                    $this->transaction->commit();
                }
            } catch (\Throwable $e) {
                if ($this->transaction->inTransaction()) {
                    $this->transaction->rollback();
                }
                // 画面に出す理由は、読める理由（Domain の例外）だけ。DB などの予期しないエラーは決まった文にし、詳細はその日のエラーに残す
                $reason = $e instanceof DomainException ? $e->getMessage() : self::UNEXPECTED_ERROR;
                $errors[] = $this->fail($schedule, $e->getMessage(), $reason, $dryRun);
                continue;
            }
            $applied++;
            if (!$dryRun) {
                // 適用は確定済み。キャッシュを消せなくても「適用失敗」にはしない（やり直すと二重に適用してしまう）
                try {
                    $this->cache->forgetAfterRoomTransfer($date, $schedule->userId);
                } catch (\Throwable $e) {
                    $errors[] = sprintf('スケジュールID=%d: 適用しましたが、キャッシュを消せませんでした（%s）', $schedule->id, $e->getMessage());
                }
            }
        }

        return new ApplyRoomTransfersOutput($applied, $errors);
    }

    /**
     * 適用できなかった異動を「適用失敗」にして理由を残す（ドライランでは変えない）。毎晩同じ失敗を繰り返さないため（#945）。
     * 印を付けられなかったときは予約中のまま残し、その理由もエラーの文に足す。
     *
     * @param string $detail その日の実行で返すエラーの詳細
     * @param string $reason 一覧に出す理由
     * @return string その日の実行で返すエラーの文
     */
    private function fail(RoomTransferSchedule $schedule, string $detail, string $reason, bool $dryRun): string
    {
        $error = sprintf('スケジュールID=%d: %s', $schedule->id, $detail);
        if ($dryRun) {
            return $error;
        }
        try {
            $this->schedules->markFailed($schedule->id, $reason, $this->clock->now()->format('Y-m-d H:i:s'));
        } catch (\Throwable $e) {
            $error .= '（適用失敗の記録もできませんでした: ' . $e->getMessage() . '）';
        }

        return $error;
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
        // 予約中のときだけ適用済みにする。バッチの途中で管理者が取り消していたら、所属・予約の変更ごと取り消す
        if (!$this->schedules->changeStatus($schedule->id, RoomTransferSchedule::STATUS_APPLIED, 'batch', $at, [RoomTransferSchedule::STATUS_PENDING])) {
            throw new ConflictException(self::CHANGED_BY_OTHER);
        }
    }
}
