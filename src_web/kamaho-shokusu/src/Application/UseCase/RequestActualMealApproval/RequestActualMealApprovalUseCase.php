<?php
declare(strict_types=1);

namespace App\Application\UseCase\RequestActualMealApproval;

use App\Application\Port\AuditActor;
use App\Application\Port\AuditLogInterface;
use App\Domain\Repository\ActualMealRepositoryInterface;
use App\Domain\ValueObject\ApprovalStatus;
use Psr\Clock\ClockInterface;

/**
 * 実食の承認申請（未承認・差し戻しの予約を未承認に戻す）。承認済みの予約はそのまま。
 *
 * ponytail: トランザクションを張っていない（以前から）。途中の更新で DB が失敗すると、それまでの予約だけ未承認に戻った状態で残る。
 * 実食を本番で動かし直すときに TransactionManagerInterface で包む。
 */
final class RequestActualMealApprovalUseCase
{
    /** 申請できる元の状態 */
    private const REQUESTABLE_FROM = [ApprovalStatus::PENDING, ApprovalStatus::REJECTED];

    public function __construct(
        private readonly ActualMealRepositoryInterface $meals,
        private readonly AuditLogInterface $auditLog,
        private readonly ClockInterface $clock,
    ) {}

    /**
     * 1件でも戻したときだけ監査ログ（actual_meal_approval_request）を残す。
     */
    public function execute(RequestActualMealApprovalInput $input): RequestActualMealApprovalOutput
    {
        $now   = $this->clock->now()->format('Y-m-d H:i:s');
        $count = 0;
        foreach ($input->keys as $key) {
            $count += $this->meals->changeApprovalStatus($key, ApprovalStatus::PENDING, self::REQUESTABLE_FROM, $input->actorName, $now);
        }

        if ($count > 0) {
            $this->auditLog->record(
                'actual_meal',
                'actual_meal_approval_request',
                new AuditActor($input->actorName, 0, $input->actorLoginId, ''),
                't_individual_reservation_info',
                null,
                ['count' => $count],
                true
            );
        }

        return new RequestActualMealApprovalOutput($count);
    }
}
