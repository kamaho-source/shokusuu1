<?php
declare(strict_types=1);

namespace App\Infrastructure\Table;

use Cake\ORM\Query\SelectQuery;

/**
 * テナント・施設単位のデータ分離を提供するテーブルTrait。
 * 業務 Table クラスに use して tenant_id / facility_id の絞り込みを有効化する。
 *
 * 使用例:
 *   $this->Reservations
 *       ->find('forTenant', tenantId: $ctx->tenantId())
 *       ->find('forFacility', facilityId: $ctx->facilityId());
 */
trait TenantAwareTableTrait
{
    /**
     * @param SelectQuery $query
     * @param int $tenantId
     * @return SelectQuery
     */
    public function findForTenant(SelectQuery $query, int $tenantId): SelectQuery
    {
        return $query->where([$this->getAlias() . '.tenant_id' => $tenantId]);
    }

    /**
     * @param SelectQuery $query
     * @param int $facilityId
     * @return SelectQuery
     */
    public function findForFacility(SelectQuery $query, int $facilityId): SelectQuery
    {
        return $query->where([$this->getAlias() . '.facility_id' => $facilityId]);
    }
}
