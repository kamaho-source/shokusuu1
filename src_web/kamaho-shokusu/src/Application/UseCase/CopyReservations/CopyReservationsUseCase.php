<?php
declare(strict_types=1);

namespace App\Application\UseCase\CopyReservations;

use App\Application\Port\TransactionManagerInterface;
use App\Domain\Entity\MealReservation;
use App\Domain\Exception\UnauthorizedException;
use App\Domain\Repository\ReservationRepositoryInterface;
use App\Domain\Service\ReservationDateRule;
use Cake\Chronos\ChronosDate;

/**
 * 予約の週コピー・月コピー。どの行をどの日へ写すかは ReservationCopyPlanner（プレビューと同じ判定）。
 * 誰がコピーしてよいか（認可）は呼び出し側で確かめる。
 *
 * - コピー先に食べない（未承認の）行があれば食べるにし、無ければ作る。直前期間は当初予定（eat_flag）を 0 にする。
 * - 全体を1つのトランザクションで行う。保存できない行があれば全部取り消して投げ直す。
 */
final class CopyReservationsUseCase
{
    public function __construct(
        private readonly ReservationCopyPlanner $planner,
        private readonly ReservationRepositoryInterface $reservations,
        private readonly TransactionManagerInterface $transaction,
    ) {}

    /**
     * @throws UnauthorizedException 所属していない部屋の指定・所属部屋なし（403）
     * @throws \Throwable 保存の失敗（取り消してから投げ直す）
     */
    public function execute(CopyReservationsInput $input): CopyReservationsOutput
    {
        $rows = $this->planner->sourceRows($input);
        if ($rows === []) {
            return new CopyReservationsOutput($input->mode, 0, 0, 0, 0);
        }
        $actor = $input->actorName !== null && $input->actorName !== '' ? $input->actorName : 'system';
        $today = $this->planner->today();
        $skipped = $invalid = 0;

        $this->transaction->begin();
        try {
            foreach ($rows as $r) {
                // 前の行を写した結果で判定が変わることがあるので、1行ずつトランザクションの中で判定する
                $dst = $this->planner->targetDate($input, $r);
                match ($this->planner->verdict($r, $dst, $today)) {
                    CopyVerdict::InvalidDate => $invalid++,
                    CopyVerdict::Skip => $skipped++,
                    CopyVerdict::Copy => $this->copyRow($r, $dst, $today, $actor),
                };
            }
            $this->transaction->commit();
        } catch (\Throwable $e) {
            if ($this->transaction->inTransaction()) {
                $this->transaction->rollback();
            }
            throw $e;
        }
        // 保存に失敗した行は無い（失敗したら上で投げ直している）ので、無効な日付・スキップ以外はすべて写した
        $copied = count($rows) - $skipped - $invalid;

        return new CopyReservationsOutput($input->mode, count($rows), $copied, $skipped, $invalid);
    }

    /**
     * 1行を写す。コピー先に食べない（未承認の）行があれば食べるにし、無ければ作る。
     *
     * @param ChronosDate $dst コピー先の日（verdict が Copy なら null ではない）
     * @throws \RuntimeException 楽観的ロック競合（optimistic_conflict）
     */
    private function copyRow(MealReservation $r, ChronosDate $dst, ChronosDate $today, string $actor): void
    {
        $dstStr = $dst->format('Y-m-d');
        // フラグはコピー元をそのまま写さず、コピー先の日付に合わせて「食べる」で作る
        $lastMinute = ReservationDateRule::isLastMinuteWindow($dst, $today);
        $existing = $this->reservations->findOne($r->userId, $r->roomId, $dstStr, $r->mealType);
        if ($existing !== null) {
            // スキップしなかったので、食べない未承認の行。直前期間は当初予定を残し、i_change_flag だけを立てる
            if (!$this->reservations->updateWithVersion($existing, 1, $lastMinute ? null : 1, $actor)) {
                throw new \RuntimeException('optimistic_conflict');
            }

            return;
        }
        $this->reservations->addMany([new MealReservation($r->userId, $dstStr, $r->roomId, $r->mealType, $lastMinute ? 0 : 1, 1)], $actor);
    }
}
