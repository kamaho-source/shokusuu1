<?php
declare(strict_types=1);

namespace App\Application\UseCase\FacilitySetting;

/**
 * 施設設定取得のOutput DTO。
 *
 * レコードが存在しない場合は fromDefaults() のデフォルト値を使用する。
 */
final class GetFacilitySettingOutput
{
    public function __construct(
        public readonly int $reservationChangeableDays,
        public readonly bool $enableWeeklyBulk,
        public readonly bool $enableMonthlyBulk,
        public readonly bool $lunchBentoExclusive,
        public readonly bool $approvalEnabled,
        public readonly bool $residentSelfEditEnabled,
        public readonly ?string $fiscalYearUpdateDate,
        public readonly ?string $exportTemplateCode,
        public readonly ?string $reservationDeadlineTime,
    ) {}

    public static function fromDefaults(): self
    {
        return new self(
            reservationChangeableDays: 7,
            enableWeeklyBulk: true,
            enableMonthlyBulk: true,
            lunchBentoExclusive: false,
            approvalEnabled: false,
            residentSelfEditEnabled: true,
            fiscalYearUpdateDate: null,
            exportTemplateCode: null,
            reservationDeadlineTime: null,
        );
    }
}
