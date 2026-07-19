<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * Phase 7: 既存データへ初期テナント・施設ID (1/1) をバックフィルする。
 *
 * tenant_id IS NULL のレコードをすべて tenant_id=1, facility_id=1 に更新する。
 * NULL のみを更新するため、マイグレーション実行が重複しても安全。
 *
 * 更新対象テーブル:
 *   m_user_info, m_room_info, m_user_group, m_room_transfer_schedule,
 *   t_reservation_info, t_individual_reservation_info,
 *   t_approval_log, t_notification, t_audit_log
 */
class BackfillTenantFacilityIds extends AbstractMigration
{
    /**
     * テーブル名 → facility_id を設定するか（falseなら tenant_id のみ）
     *
     * @var array<string, bool>
     */
    private array $targets = [
        'm_user_info'                   => true,
        'm_room_info'                   => true,
        'm_user_group'                  => true,
        'm_room_transfer_schedule'      => true,
        't_reservation_info'            => true,
        't_individual_reservation_info' => true,
        't_approval_log'                => true,
        't_notification'                => true,
        't_audit_log'                   => true,
    ];

    public function up(): void
    {
        foreach ($this->targets as $tableName => $setFacility) {
            if ($setFacility) {
                $this->execute("
                    UPDATE {$tableName}
                    SET tenant_id = 1, facility_id = 1
                    WHERE tenant_id IS NULL
                ");
            } else {
                $this->execute("
                    UPDATE {$tableName}
                    SET tenant_id = 1
                    WHERE tenant_id IS NULL
                ");
            }
        }
    }

    public function down(): void
    {
        foreach (array_keys($this->targets) as $tableName) {
            $this->execute("
                UPDATE {$tableName}
                SET tenant_id = NULL, facility_id = NULL
                WHERE tenant_id = 1
            ");
        }
    }
}
