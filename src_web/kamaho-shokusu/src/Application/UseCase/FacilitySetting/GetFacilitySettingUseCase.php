<?php
declare(strict_types=1);

namespace App\Application\UseCase\FacilitySetting;

use App\Model\Table\FacilitySettingsTable;

/**
 * 施設設定を取得するユースケース。
 *
 * 設定レコードが存在しない場合はデフォルト値を返す。
 * これにより施設設定を明示的に登録しなくてもシステムが動作する。
 */
final class GetFacilitySettingUseCase
{
    public function __construct(
        private readonly FacilitySettingsTable $facilitySettings,
    ) {}

    public function execute(GetFacilitySettingInput $input): GetFacilitySettingOutput
    {
        $setting = $this->facilitySettings->find()
            ->where([
                'facility_id' => $input->facilityId,
                'tenant_id'   => $input->tenantId,
            ])
            ->first();

        if ($setting === null) {
            return GetFacilitySettingOutput::fromDefaults();
        }

        return new GetFacilitySettingOutput(
            reservationChangeableDays: (int)$setting->reservation_changeable_days,
            enableWeeklyBulk: (bool)$setting->enable_weekly_bulk,
            enableMonthlyBulk: (bool)$setting->enable_monthly_bulk,
            lunchBentoExclusive: (bool)$setting->lunch_bento_exclusive,
            approvalEnabled: (bool)$setting->approval_enabled,
            residentSelfEditEnabled: (bool)$setting->resident_self_edit_enabled,
            fiscalYearUpdateDate: $setting->fiscal_year_update_date?->format('Y-m-d'),
            exportTemplateCode: $setting->export_template_code,
            reservationDeadlineTime: $setting->reservation_deadline_time !== null
                ? (string)$setting->reservation_deadline_time
                : null,
        );
    }
}
