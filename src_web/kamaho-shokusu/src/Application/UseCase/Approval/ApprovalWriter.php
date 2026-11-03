<?php
declare(strict_types=1);

namespace App\Application\UseCase\Approval;

use App\Application\UseCase\ReflectApprovedToReservation\ReflectApprovedToReservationOutput;
use App\Domain\Repository\ApprovalRepositoryInterface;
use App\Domain\Service\ApprovalRule;
use App\Domain\ValueObject\ApprovalKey;
use Cake\Chronos\ChronosDate;
use Psr\Clock\ClockInterface;

/**
 * 承認の UseCase が共有する書き込み（状態の変更と承認ログ、最終承認済みの集計への反映）。
 * トランザクションは呼び出し側の UseCase が持つ。
 */
final class ApprovalWriter
{
    public function __construct(
        private readonly ApprovalRepositoryInterface $approvals,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * 決まりに当てはまる予約の状態を変え、変えたものに承認ログを書く。
     * 自分の予約・担当外の部屋・今の状態が違う予約は飛ばす。
     *
     * @param list<ApprovalKey> $keys
     * @return list<ApprovalKey> 実際に変えたもの（監査ログの件数・対象IDにはこれを使う）
     * @throws \RuntimeException 承認ログを保存できないとき
     */
    public function changeStatuses(array $keys, ApprovalRule $rule, int $approverId, string $actorName, ?string $reason, string $now): array
    {
        $changed = [];
        foreach ($keys as $key) {
            if (!$rule->covers($key)) {
                continue;
            }
            if (!$this->approvals->changeStatus($key, $rule->newStatus, $rule->allowedFrom, $actorName, $now)) {
                continue;
            }
            $changed[] = $key;
            $this->approvals->addLog($key, $rule->newStatus, $approverId, $reason, $now);
        }

        return $changed;
    }

    /**
     * 最終承認済みの予約を、部屋・日付・食事区分ごとの食数（食べる・食べない）にして集計へ書く。
     * 「食べる」は食数の報告・カレンダーと同じ基準（直前期間の変更も含む）。
     *
     * @throws \RuntimeException 保存できないとき
     */
    public function reflect(?int $roomId, ?string $dateFrom, ?string $dateTo, string $actorName): ReflectApprovedToReservationOutput
    {
        $reservations = $this->approvals->findFinalApproved($roomId, $dateFrom, $dateTo);
        if ($reservations === []) {
            return new ReflectApprovedToReservationOutput(0, 0);
        }
        $now   = $this->clock->now();
        $today = new ChronosDate($now);

        /** @var array<string, array{roomId: int, date: string, mealType: int, eating: int, notEating: int}> $groups */
        $groups = [];
        foreach ($reservations as $reservation) {
            $groupKey = $reservation->roomId . '_' . $reservation->date . '_' . $reservation->mealType;
            $groups[$groupKey] ??= ['roomId' => $reservation->roomId, 'date' => $reservation->date, 'mealType' => $reservation->mealType, 'eating' => 0, 'notEating' => 0];
            $groups[$groupKey][$reservation->isEffective($today) ? 'eating' : 'notEating']++;
        }

        $at = $now->format('Y-m-d H:i:s');
        foreach ($groups as $group) {
            $this->approvals->saveMealCount($group['roomId'], $group['date'], $group['mealType'], $group['eating'], $group['notEating'], $actorName, $at);
        }

        return new ReflectApprovedToReservationOutput(count($groups), count($reservations));
    }
}
