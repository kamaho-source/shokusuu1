<?php
declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\MealReservation;
use App\Domain\ValueObject\ApprovalKey;

/**
 * 承認の保存先（予約の承認状態・承認ログ・最終承認済みの集計への反映）。
 */
interface ApprovalRepositoryInterface
{
    /**
     * 予約の承認状態を、今の状態が $allowedFrom のどれかのときだけ $newStatus にする（更新日時・更新者も）。
     *
     * @param list<int> $allowedFrom
     * @return bool 変えたか（今の状態が違う・予約が無いときは false）
     */
    public function changeStatus(ApprovalKey $key, int $newStatus, array $allowedFrom, string $actorName, string $now): bool;

    /**
     * 承認ログ（承認・差し戻し）を1行書く。
     *
     * @throws \RuntimeException 保存できないとき
     */
    public function addLog(ApprovalKey $key, int $status, int $approverId, ?string $reason, string $now): void;

    /**
     * 最終承認済みの予約（削除済みの利用者を除く）。null は絞らない。
     *
     * @return list<MealReservation>
     */
    public function findFinalApproved(?int $roomId, ?string $dateFrom, ?string $dateTo): array;

    /**
     * 部屋・日付・食事区分の食数（予約集計）を書く。あれば人数を更新し、無ければ作る。
     *
     * @throws \RuntimeException 保存できないとき
     */
    public function saveMealCount(int $roomId, string $date, int $mealType, int $eating, int $notEating, string $actorName, string $now): void;
}
