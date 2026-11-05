<?php
declare(strict_types=1);

namespace App\Application\UseCase\RejectApproval;

use App\Domain\ValueObject\ApprovalKey;

/**
 * 状態を変えた予約（自分の予約・担当外・今の状態が違うものは含まない）。
 */
final class RejectApprovalOutput
{
    /**
     * @param list<ApprovalKey> $changedKeys
     */
    public function __construct(
        public readonly array $changedKeys,
    ) {}

    /** 1件でも変えたか（画面の success）。 */
    public function succeeded(): bool
    {
        return $this->changedKeys !== [];
    }
}
