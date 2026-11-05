<?php
declare(strict_types=1);

namespace App\Application\UseCase\RequestActualMealApproval;

use App\Domain\ValueObject\ApprovalKey;

/**
 * 実食の承認申請の Input DTO（申請する予約と操作者）。
 */
final class RequestActualMealApprovalInput
{
    /**
     * @param list<ApprovalKey> $keys
     */
    public function __construct(
        public readonly array $keys,
        public readonly string $actorName,
        public readonly string $actorLoginId = '',
    ) {}
}
