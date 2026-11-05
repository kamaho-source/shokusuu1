<?php
declare(strict_types=1);

namespace App\Application\UseCase\RequestActualMealApproval;

/**
 * 実食の承認申請の結果（未承認に戻した件数。0 件でも失敗にはしない）。
 */
final class RequestActualMealApprovalOutput
{
    public function __construct(
        public readonly int $count,
    ) {}
}
