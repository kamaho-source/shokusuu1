<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

class AddTenantFacilityToMUserInfo extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('m_user_info');

        $table
            ->addColumn('tenant_id', 'integer', [
                'null'    => true,
                'default' => null,
                'after'   => 'i_id_user',
                'comment' => '所属テナントID（Phase 7 で NOT NULL 化）',
            ])
            ->addColumn('facility_id', 'integer', [
                'null'    => true,
                'default' => null,
                'after'   => 'tenant_id',
                'comment' => '所属施設ID（Phase 7 で NOT NULL 化）',
            ])
            ->save();

        // c_login_account の単独 UNIQUE 制約を削除（テナント単位の複合制約に置換）
        $exists = $this->fetchRow("
            SELECT COUNT(*) AS cnt
            FROM information_schema.STATISTICS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME   = 'm_user_info'
              AND INDEX_NAME   = 'c_login_account'
              AND NON_UNIQUE   = 0
        ");
        if ($exists && $exists['cnt'] > 0) {
            $this->execute('ALTER TABLE m_user_info DROP INDEX `c_login_account`');
        }

        $table
            ->addIndex(['tenant_id', 'c_login_account'], [
                'name'   => 'uq_tenant_login_account',
                'unique' => true,
            ])
            ->addIndex(['tenant_id'],   ['name' => 'idx_m_user_info_tenant_id'])
            ->addIndex(['facility_id'], ['name' => 'idx_m_user_info_facility_id'])
            ->save();

        // FK はデータ移行（Phase 7）後に追加するため、ここでは追加しない
    }

    public function down(): void
    {
        $table = $this->table('m_user_info');

        $table
            ->removeIndex(['tenant_id', 'c_login_account'])
            ->removeIndex(['tenant_id'])
            ->removeIndex(['facility_id'])
            ->save();

        $table
            ->removeColumn('tenant_id')
            ->removeColumn('facility_id')
            ->save();

        // 単独 UNIQUE 制約を復元
        $table
            ->addIndex(['c_login_account'], ['name' => 'c_login_account', 'unique' => true])
            ->save();
    }
}
