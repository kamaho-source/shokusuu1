<?php
declare(strict_types=1);

namespace App\Application\UseCase\FacilitySetting;

use App\Model\Table\FacilitySettingsTable;
use Cake\ORM\Exception\PersistenceFailedException;

/**
 * 施設設定を保存するユースケース（新規作成 or 更新）。
 *
 * facility_id に UNIQUE 制約があるため、既存レコードがあれば UPDATE、なければ INSERT する。
 *
 * @throws PersistenceFailedException バリデーションエラーまたは DB エラー時
 */
final class SaveFacilitySettingUseCase
{
    public function __construct(
        private readonly FacilitySettingsTable $facilitySettings,
    ) {}

    public function execute(SaveFacilitySettingInput $input): void
    {
        $existing = $this->facilitySettings->find()
            ->where([
                'facility_id' => $input->facilityId,
                'tenant_id'   => $input->tenantId,
            ])
            ->first();

        $data = [
            'reservation_changeable_days' => $input->reservationChangeableDays,
            'enable_weekly_bulk'          => $input->enableWeeklyBulk,
            'enable_monthly_bulk'         => $input->enableMonthlyBulk,
            'lunch_bento_exclusive'       => $input->lunchBentoExclusive,
            'approval_enabled'            => $input->approvalEnabled,
            'resident_self_edit_enabled'  => $input->residentSelfEditEnabled,
            'fiscal_year_update_date'     => $input->fiscalYearUpdateDate,
            'export_template_code'        => $input->exportTemplateCode,
            'reservation_deadline_time'   => $input->reservationDeadlineTime,
        ];

        if ($existing === null) {
            $entity = $this->facilitySettings->newEntity($data);
            $entity->set('facility_id', $input->facilityId, ['guard' => false]);
            $entity->set('tenant_id',   $input->tenantId,   ['guard' => false]);
        } else {
            $entity = $this->facilitySettings->patchEntity($existing, $data);
        }

        $this->facilitySettings->saveOrFail($entity);
    }
}
