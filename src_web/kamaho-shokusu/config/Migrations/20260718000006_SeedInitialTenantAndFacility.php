<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * Phase 7: 既存施設を初期テナント・初期施設としてシードする。
 *
 * 既存の単一施設環境を以下の初期値でテナント化する。
 *   tenant_id = 1, tenant_code = 'default', status = 'active'
 *   facility_id = 1, facility_code = 'facility-01', name = '初期施設'
 *
 * 既にレコードが存在する場合はスキップする（冪等性保証）。
 */
class SeedInitialTenantAndFacility extends AbstractMigration
{
    public function up(): void
    {
        $now = date('Y-m-d H:i:s');

        $tenantExists = $this->fetchRow('SELECT COUNT(*) AS cnt FROM tenants WHERE id = 1');
        if ((int)($tenantExists['cnt'] ?? 0) === 0) {
            $this->execute("
                INSERT INTO tenants (id, tenant_code, name, status, plan_code, created_at, updated_at)
                VALUES (1, 'default', '初期テナント', 'active', 'standard', '{$now}', '{$now}')
            ");
        }

        $facilityExists = $this->fetchRow('SELECT COUNT(*) AS cnt FROM facilities WHERE id = 1');
        if ((int)($facilityExists['cnt'] ?? 0) === 0) {
            $this->execute("
                INSERT INTO facilities (id, tenant_id, facility_code, name, timezone, is_active, created_at, updated_at)
                VALUES (1, 1, 'facility-01', '初期施設', 'Asia/Tokyo', 1, '{$now}', '{$now}')
            ");
        }
    }

    public function down(): void
    {
        $this->execute('DELETE FROM facilities WHERE id = 1');
        $this->execute('DELETE FROM tenants WHERE id = 1');
    }
}
