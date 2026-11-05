<?php
declare(strict_types=1);

namespace App\Application\UseCase\ApproveByBlockLeader;

use App\Application\Port\AuditLogInterface;
use App\Application\Port\TransactionManagerInterface;
use App\Application\Query\RoomQueryInterface;
use App\Application\UseCase\Approval\ApprovalWriter;
use App\Domain\Service\ApprovalRule;
use App\Domain\ValueObject\ApprovalKey;
use Psr\Clock\ClockInterface;

/**
 * ブロック長の承認（未承認 → ブロック長承認済）。
 *
 * 自分の予約は除く（自己承認の防止）。承認者が管理者でなければ、担当部屋（所属中で削除されていない部屋）の予約だけ（#989）。
 */
final class ApproveByBlockLeaderUseCase
{
    public function __construct(
        private readonly ApprovalWriter $writer,
        private readonly RoomQueryInterface $rooms,
        private readonly TransactionManagerInterface $transaction,
        private readonly AuditLogInterface $auditLog,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * @throws \InvalidArgumentException 対象のキーの形が崩れているとき（何も変えない）
     * @throws \Throwable DB の失敗（取り消してから投げ直す）
     */
    public function execute(ApproveByBlockLeaderInput $input): ApproveByBlockLeaderOutput
    {
        $approverId = $input->actor->id;
        $rule = ApprovalRule::blockLeaderApprove($approverId, $input->approverIsAdmin ? null : $this->rooms->getUserRoomIds($approverId));
        $now  = $this->clock->now()->format('Y-m-d H:i:s');

        $this->transaction->begin();
        try {
            $changed = $this->writer->changeStatuses(ApprovalKey::listFrom($input->keys), $rule, $approverId, $input->actor->name, null, $now);
            $this->transaction->commit();
        } catch (\Throwable $e) {
            $this->transaction->rollback();
            throw $e;
        }

        $succeeded = $changed !== [];
        $this->auditLog->record(
            'approval',
            'approval_block_leader',
            $input->actor,
            't_individual_reservation_info',
            implode(',', array_map(static fn(ApprovalKey $key): string => $key->auditId(), $changed)),
            ['count' => count($changed)],
            $succeeded,
            $succeeded ? null : '承認対象の予約がありません（既に承認済み・対象外・競合の可能性）'
        );

        return new ApproveByBlockLeaderOutput($changed);
    }
}
