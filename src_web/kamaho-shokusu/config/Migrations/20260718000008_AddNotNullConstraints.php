<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * Phase 7: 主要テーブルへ NOT NULL 制約を追加する。
 *
 * Migration 007 (BackfillTenantFacilityIds) 実行後に適用すること。
 * tenant_id IS NULL のレコードが残っている場合はエラーになる。
 *
 * NOT NULL 化する列:
 *   m_room_info.tenant_id, facility_id
 *   m_room_transfer_schedule.tenant_id, facility_id
 *   t_reservation_info.tenant_id, facility_id
 *   t_individual_reservation_info.tenant_id, facility_id
 *   t_approval_log.tenant_id, facility_id
 *
 * ※ m_user_info は system_admin が facility_id を持たないケースがあるため NULL 許容を維持する。
 * ※ t_notification / t_audit_log は非同期記録が残る可能性があるため NULL 許容を維持する。
 * ※ m_user_group の facility_id は NULL 許容を維持（中間テーブルのため）。
 */
class AddNotNullConstraints extends AbstractMigration
{
    /** テーブル → [tenant_id を NOT NULL 化, facility_id を NOT NULL 化] */
    private array $targets = [
        'm_room_info'                   => [true, true],
        'm_room_transfer_schedule'      => [true, true],
        't_reservation_info'            => [true, true],
        't_individual_reservation_info' => [true, true],
        't_approval_log'                => [true, true],
    ];

    public function up(): void
    {
        foreach ($this->targets as $tableName => [$notNullTenant, $notNullFacility]) {
            $table = $this->table($tableName);

            if ($notNullTenant) {
                $table->changeColumn('tenant_id', 'integer', [
                    'null'    => false,
                    'comment' => '所属テナントID',
                ]);
            }
            if ($notNullFacility) {
                $table->changeColumn('facility_id', 'integer', [
                    'null'    => false,
                    'comment' => '所属施設ID',
                ]);
            }

            $table->update();
        }
    }

    public function down(): void
    {
        foreach ($this->targets as $tableName => [$notNullTenant, $notNullFacility]) {
            $table = $this->table($tableName);

            if ($notNullTenant) {
                $table->changeColumn('tenant_id', 'integer', [
                    'null'    => true,
                    'default' => null,
                ]);
            }
            if ($notNullFacility) {
                $table->changeColumn('facility_id', 'integer', [
                    'null'    => true,
                    'default' => null,
                ]);
            }

            $table->update();
        }
    }
}
