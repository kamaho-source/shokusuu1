<?php
declare(strict_types=1);

namespace App\Application\UseCase\RejectApproval;

use App\Application\Port\ApprovalNotifierInterface;
use App\Application\Port\AuditLogInterface;
use App\Application\Port\TransactionManagerInterface;
use App\Application\Query\RoomQueryInterface;
use App\Application\UseCase\Approval\ApprovalWriter;
use App\Domain\Service\ApprovalRule;
use App\Domain\ValueObject\ApprovalKey;
use Psr\Clock\ClockInterface;

/**
 * 差し戻し（未承認・ブロック長承認済 → 差し戻し）と、本人への通知。
 *
 * 承認と対称に、ブロック長は自分の予約を除き、承認者が管理者でなければ担当部屋の予約だけ（#989）。
 * 管理者の差し戻しは自分の予約も含め、全部屋が対象。
 */
final class RejectApprovalUseCase
{
    public function __construct(
        private readonly ApprovalWriter $writer,
        private readonly RoomQueryInterface $rooms,
        private readonly TransactionManagerInterface $transaction,
        private readonly ApprovalNotifierInterface $notifier,
        private readonly AuditLogInterface $auditLog,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @throws \InvalidArgumentException 対象のキーの形が崩れているとき（何も変えない）
     * @throws \Throwable DB の失敗・通知を保存できないとき（取り消してから投げ直す）
     */
    public function execute(RejectApprovalInput $input): RejectApprovalOutput
    {
        $approverId = $input->actor->id;
        $rule = $input->byBlockLeader
            ? ApprovalRule::blockLeaderReject($approverId, $input->approverIsAdmin ? null : $this->rooms->getUserRoomIds($approverId))
            : ApprovalRule::adminReject();
        $now = $this->clock->now()->format('Y-m-d H:i:s');

        $this->transaction->begin();
        try {
            $changed = $this->writer->changeStatuses(ApprovalKey::listFrom($input->keys), $rule, $approverId, $input->actor->name, $input->reason, $now);
            if ($changed !== []) {
                $this->notifier->rejected($changed, $approverId, $input->reason, $now);
            }
            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            throw $e;
        }

        $succeeded = $changed !== [];
        $this->auditLog->record(
            'approval',
            'approval_rejected',
            $input->actor,
            't_individual_reservation_info',
            implode(',', array_map(static fn(ApprovalKey $key): string => $key->auditId(), $changed)),
            ['count' => count($changed), 'reject_reason' => $input->reason],
            $succeeded,
            $succeeded ? null : '却下対象の予約がありません（既に承認済み・対象外・競合の可能性）'
        );

        return new RejectApprovalOutput($changed);
    }
}
