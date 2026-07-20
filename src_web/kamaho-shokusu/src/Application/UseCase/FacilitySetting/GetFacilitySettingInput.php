<?php
declare(strict_types=1);

namespace App\Application\UseCase\FacilitySetting;

/**
 * 施設設定取得のInput DTO。
 */
final class GetFacilitySettingInput
{
    public function __construct(
        public readonly int $facilityId,
        public readonly int $tenantId,
    ) {}
}
