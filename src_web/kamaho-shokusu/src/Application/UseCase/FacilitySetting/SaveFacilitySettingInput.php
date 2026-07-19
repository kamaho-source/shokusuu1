<?php
declare(strict_types=1);

namespace App\Application\UseCase\FacilitySetting;

/**
 * 施設設定保存のInput DTO。
 *
 * 未指定フィールドはデフォルト値として扱われる。
 */
final class SaveFacilitySettingInput
{
    public function __construct(
        public readonly int $facilityId,
        public readonly int $tenantId,
        public readonly int $reservationChangeableDays = 7,
        public readonly bool $enableWeeklyBulk = true,
        public readonly bool $enableMonthlyBulk = true,
        public readonly bool $lunchBentoExclusive = false,
        public readonly bool $approvalEnabled = false,
        public readonly bool $residentSelfEditEnabled = true,
        public readonly ?string $fiscalYearUpdateDate = null,
        public readonly ?string $exportTemplateCode = null,
        public readonly ?string $reservationDeadlineTime = null,
    ) {
        if ($reservationChangeableDays < 0) {
            throw new \InvalidArgumentException('reservationChangeableDays must be >= 0.');
        }
    }
}
