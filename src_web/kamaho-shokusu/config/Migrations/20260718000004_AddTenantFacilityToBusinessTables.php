<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * Phase 3: 全主要業務テーブルへ tenant_id / facility_id を追加する。
 *
 * 追加対象テーブル:
 *   m_room_info, m_user_group, m_room_transfer_schedule,
 *   t_reservation_info, t_individual_reservation_info,
 *   t_approval_log, t_notification, t_audit_log
 *
 * Phase 7 でデータ移行後に NOT NULL 化・FK・UNIQUE 制約を追加する。
 */
class AddTenantFacilityToBusinessTables extends AbstractMigration
{
    /** @var array<array{table: string, after: string}> */
    private array $targets = [
        ['table' => 'm_room_info',                   'after' => 'i_id_room'],
        ['table' => 'm_user_group',                  'after' => 'i_id_room'],
        ['table' => 'm_room_transfer_schedule',      'after' => 'i_id'],
        ['table' => 't_reservation_info',            'after' => 'c_reservation_type'],
        ['table' => 't_individual_reservation_info', 'after' => 'i_reservation_type'],
        ['table' => 't_approval_log',                'after' => 'i_id_approval'],
        ['table' => 't_notification',                'after' => 'i_id_notification'],
        ['table' => 't_audit_log',                   'after' => 'i_id_audit'],
    ];

    public function up(): void
    {
        foreach ($this->targets as ['table' => $tableName, 'after' => $after]) {
            $this->table($tableName)
                ->addColumn('tenant_id', 'integer', [
                    'null'    => true,
                    'default' => null,
                    'after'   => $after,
                    'comment' => '所属テナントID（Phase 7 で NOT NULL 化）',
                ])
                ->addColumn('facility_id', 'integer', [
                    'null'    => true,
                    'default' => null,
                    'after'   => 'tenant_id',
                    'comment' => '所属施設ID（Phase 7 で NOT NULL 化）',
                ])
                ->addIndex(['tenant_id'],   ['name' => "idx_{$tableName}_tenant"])
                ->addIndex(['facility_id'], ['name' => "idx_{$tableName}_facility"])
                ->update();
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->targets) as ['table' => $tableName]) {
            $this->table($tableName)
                ->removeIndexByName("idx_{$tableName}_facility")
                ->removeIndexByName("idx_{$tableName}_tenant")
                ->removeColumn('facility_id')
                ->removeColumn('tenant_id')
                ->update();
        }
    }
}
