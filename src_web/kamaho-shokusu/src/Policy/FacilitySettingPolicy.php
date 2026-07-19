<?php
declare(strict_types=1);

namespace App\Policy;

use App\Model\Entity\FacilitySetting;
use Authorization\IdentityInterface;

/**
 * 施設設定の認可ポリシー
 *
 * - 施設管理者（facility_admin）: 所属施設の設定を管理できる
 * - テナント管理者（tenant_admin）: テナント内の全施設の設定を管理できる
 * - システム管理者（platform_admin）: 全設定を管理できる
 */
class FacilitySettingPolicy
{
    use PolicyTrait;

    public function canIndex(?IdentityInterface $user, FacilitySetting $resource): bool
    {
        return $this->isAdmin($user);
    }

    public function canView(?IdentityInterface $user, FacilitySetting $resource): bool
    {
        return $this->isAdmin($user) && $this->isSameTenant($user, $resource);
    }

    public function canEdit(?IdentityInterface $user, FacilitySetting $resource): bool
    {
        return $this->isAdmin($user) && $this->isSameTenant($user, $resource);
    }
}
