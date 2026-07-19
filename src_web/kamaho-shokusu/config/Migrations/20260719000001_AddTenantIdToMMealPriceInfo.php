<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * m_meal_price_info に tenant_id / facility_id を追加する。
 * 既存データは初期テナント (id=1) / 初期施設 (id=1) に紐付ける。
 */
class AddTenantIdToMMealPriceInfo extends AbstractMigration
{
    public function up(): void
    {
        $this->table('m_meal_price_info')
            ->addColumn('tenant_id', 'integer', [
                'null'    => true,
                'default' => null,
                'after'   => 'i_id',
                'comment' => '所属テナントID',
            ])
            ->addColumn('facility_id', 'integer', [
                'null'    => true,
                'default' => null,
                'after'   => 'tenant_id',
                'comment' => '所属施設ID',
            ])
            ->addIndex(['tenant_id'],   ['name' => 'idx_m_meal_price_info_tenant'])
            ->addIndex(['facility_id'], ['name' => 'idx_m_meal_price_info_facility'])
            ->update();

        // 既存データを初期テナント・施設に紐付ける
        $this->execute('UPDATE m_meal_price_info SET tenant_id = 1, facility_id = 1 WHERE tenant_id IS NULL');
    }

    public function down(): void
    {
        $this->table('m_meal_price_info')
            ->removeIndexByName('idx_m_meal_price_info_facility')
            ->removeIndexByName('idx_m_meal_price_info_tenant')
            ->removeColumn('facility_id')
            ->removeColumn('tenant_id')
            ->update();
    }
}
