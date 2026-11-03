<?php
declare(strict_types=1);

namespace App\Domain\Entity;

use App\Domain\Service\ReservationDateRule;
use App\Domain\ValueObject\ApprovalStatus;
use Cake\Chronos\ChronosDate;

/**
 * 食事予約の1行（利用者 × 日付 × 部屋 × 食事区分）。
 *
 * eat_flag は当初予定、i_change_flag は直前期間の変更後の値。どちらを数えるかは ReservationDateRule が決める。
 * version は楽観的ロックの版（i_version）。読み取ったときの値をそのまま持つ。
 */
final class MealReservation
{
    public function __construct(
        public readonly int $userId,
        public readonly string $date,
        public readonly int $roomId,
        public readonly int $mealType,
        public readonly ?int $eatFlag,
        public readonly ?int $changeFlag,
        public readonly ?int $version = 1,
        public readonly ?int $approvalStatus = null,
    ) {}

    /** 承認済み（1・2）の i_approval_status。承認済みの行は変更しない。 */
    public const APPROVED_STATUSES = ApprovalStatus::APPROVED;

    /** 承認済みか（NULL は未承認）。 */
    public function isApproved(): bool
    {
        return ApprovalStatus::isApproved($this->approvalStatus);
    }

    /** 食数として数える値（1=食べる）。 */
    public function countedValue(ChronosDate $today): int
    {
        return ReservationDateRule::countedValue(new ChronosDate($this->date), $this->eatFlag, $this->changeFlag, $today);
    }

    /** 有効（食べる）か。 */
    public function isEffective(ChronosDate $today): bool
    {
        return $this->countedValue($today) === 1;
    }
}
