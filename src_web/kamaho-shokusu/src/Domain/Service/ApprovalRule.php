<?php
declare(strict_types=1);

namespace App\Domain\Service;

use App\Domain\ValueObject\ApprovalKey;
use App\Domain\ValueObject\ApprovalStatus;

/**
 * 承認・差し戻しの1回の操作の決まり（変える先の状態・変えてよい元の状態・自分の予約を除くか・担当部屋で絞るか）。
 *
 * - ブロック長の承認・差し戻しは、自分の予約を除く（自己承認の防止）。担当部屋は呼び出し元が渡す。
 * - 管理者の承認・差し戻しは、自分の予約も対象にし（#144）、全部屋を対象にする。
 */
final class ApprovalRule
{
    /**
     * @param list<int> $allowedFrom
     * @param list<int>|null $roomIds 対象にする部屋。null は全部屋
     */
    private function __construct(
        public readonly int $newStatus,
        public readonly array $allowedFrom,
        public readonly ?int $excludedUserId,
        public readonly ?array $roomIds,
    ) {}

    /**
     * ブロック長の承認（ブロック長承認済にする）。
     *
     * @param list<int>|null $roomIds 承認者の担当部屋。null（全部屋）は承認者が管理者のときだけ渡す
     */
    public static function blockLeaderApprove(int $approverId, ?array $roomIds): self
    {
        return new self(ApprovalStatus::BLOCK_LEADER, ApprovalStatus::allowedFrom(ApprovalStatus::BLOCK_LEADER), $approverId, $roomIds);
    }

    /** 管理者の最終承認。 */
    public static function adminApprove(): self
    {
        return new self(ApprovalStatus::ADMIN, ApprovalStatus::allowedFrom(ApprovalStatus::ADMIN), null, null);
    }

    /**
     * ブロック長の差し戻し。
     *
     * @param list<int>|null $roomIds 承認者の担当部屋。null（全部屋）は承認者が管理者のときだけ渡す
     */
    public static function blockLeaderReject(int $approverId, ?array $roomIds): self
    {
        return new self(ApprovalStatus::REJECTED, ApprovalStatus::allowedFrom(ApprovalStatus::REJECTED), $approverId, $roomIds);
    }

    /** 管理者の差し戻し。 */
    public static function adminReject(): self
    {
        return new self(ApprovalStatus::REJECTED, ApprovalStatus::allowedFrom(ApprovalStatus::REJECTED), null, null);
    }

    /** この操作の対象か（自分の予約・担当外の部屋なら false）。元の状態は更新の条件で見る。 */
    public function covers(ApprovalKey $key): bool
    {
        if ($this->excludedUserId !== null && $key->userId === $this->excludedUserId) {
            return false;
        }

        return $this->roomIds === null || in_array($key->roomId, $this->roomIds, true);
    }
}
