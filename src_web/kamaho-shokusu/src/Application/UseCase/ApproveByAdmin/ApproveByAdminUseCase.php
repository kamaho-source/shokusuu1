<?php
declare(strict_types=1);

namespace App\Application\UseCase\ApproveByAdmin;

use App\Application\Port\AuditLogInterface;
use App\Application\Port\TransactionManagerInterface;
use App\Application\UseCase\Approval\ApprovalWriter;
use App\Domain\Service\ApprovalRule;
use App\Domain\ValueObject\ApprovalKey;
use Psr\Clock\ClockInterface;

/**
 * 管理者の最終承認（未承認・ブロック長承認済 → 管理者承認済）と、承認した部屋・期間の集計への反映。
 *
 * 管理者は自分の予約も最終承認できる（#144）。全部屋が対象。
 * 状態の更新・承認ログ・集計への反映を1つのトランザクションにし、反映で失敗したら承認も取り消す（#989）。
 */
final class ApproveByAdminUseCase
{
    public function __construct(
        private readonly ApprovalWriter $writer,
        private readonly TransactionManagerInterface $transaction,
        private readonly AuditLogInterface $auditLog,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @throws \Throwable キーの形が崩れている・集計への反映で保存に失敗したとき（承認も取り消し、失敗を監査ログに残してから投げ直す）
     */
    public function execute(ApproveByAdminInput $input): ApproveByAdminOutput
    {
        try {
            $changed = $this->approveAndReflect($input);
        } catch (\Throwable $e) {
            // 取り消した事実は監査ログに残す（成功とは記録しない）。例外はそのまま返す
            $this->auditLog->record('approval', 'approval_admin', $input->actor, 't_individual_reservation_info', null, ['count' => 0, 'error' => $e->getMessage()], false, '集計への反映に失敗したため、承認を取り消しました');
            throw $e;
        }

        $succeeded = $changed !== [];
        $this->auditLog->record(
            'approval',
            'approval_admin',
            $input->actor,
            't_individual_reservation_info',
            implode(',', array_map(static fn(ApprovalKey $key): string => $key->auditId(), $changed)),
            ['count' => count($changed)],
            $succeeded,
            $succeeded ? null : '承認対象の予約がありません（既に承認済み・対象外・競合の可能性）'
        );

        return new ApproveByAdminOutput($changed);
    }

    /**
     * @return list<ApprovalKey> 承認したもの
     * @throws \Throwable
     */
    private function approveAndReflect(ApproveByAdminInput $input): array
    {
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $this->transaction->begin();
        try {
            $changed = $this->writer->changeStatuses(ApprovalKey::listFrom($input->keys), ApprovalRule::adminApprove(), $input->actor->id, $input->actor->name, null, $now);
            if ($changed !== []) {
                // 承認した予約の部屋ごとに、日付の範囲をまとめて反映する
                $dates = array_map(static fn(ApprovalKey $key): string => $key->date, $changed);
                foreach (array_unique(array_map(static fn(ApprovalKey $key): int => $key->roomId, $changed)) as $roomId) {
                    $this->writer->reflect($roomId, min($dates), max($dates), $input->actor->name);
                }
            }
            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            throw $e;
        }

        return $changed;
    }
}
