<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * 施設別設定テーブルを追加する。
 *
 * 施設ごとに異なる業務ルールを保持する。
 * facility_id に UNIQUE 制約を持ち、1施設につき1レコードのみ存在する。
 */
class CreateFacilitySettings extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('facility_settings');
        $table
            ->addColumn('facility_id', 'integer', [
                'null'     => false,
                'signed'   => false,
                'comment'  => '施設ID',
            ])
            ->addColumn('tenant_id', 'integer', [
                'null'     => false,
                'signed'   => false,
                'comment'  => 'テナントID',
            ])
            ->addColumn('reservation_changeable_days', 'integer', [
                'null'    => false,
                'default' => 7,
                'comment' => '予約変更可能日数（当日を0として何日前まで変更できるか）',
            ])
            ->addColumn('enable_weekly_bulk', 'boolean', [
                'null'    => false,
                'default' => true,
                'comment' => '週単位一括予約を許可する',
            ])
            ->addColumn('enable_monthly_bulk', 'boolean', [
                'null'    => false,
                'default' => true,
                'comment' => '月単位一括予約を許可する',
            ])
            ->addColumn('lunch_bento_exclusive', 'boolean', [
                'null'    => false,
                'default' => false,
                'comment' => '昼食と弁当を排他にする（両方同時登録を禁止）',
            ])
            ->addColumn('approval_enabled', 'boolean', [
                'null'    => false,
                'default' => false,
                'comment' => '承認ワークフロー機能を利用する',
            ])
            ->addColumn('resident_self_edit_enabled', 'boolean', [
                'null'    => false,
                'default' => true,
                'comment' => '利用者本人が自分の予約を変更できる',
            ])
            ->addColumn('fiscal_year_update_date', 'date', [
                'null'    => true,
                'comment' => '年度更新日（MM-DD形式で保存）',
            ])
            ->addColumn('export_template_code', 'string', [
                'limit'   => 50,
                'null'    => true,
                'comment' => 'Excel出力テンプレートコード',
            ])
            ->addColumn('reservation_deadline_time', 'time', [
                'null'    => true,
                'comment' => '予約締切時刻（当日の予約締切）',
            ])
            ->addColumn('created_at', 'datetime', ['null' => false])
            ->addColumn('updated_at', 'datetime', ['null' => false])
            ->addIndex(['facility_id'], [
                'unique' => true,
                'name'   => 'unique_facility_settings_facility',
            ])
            ->addIndex(['tenant_id'], [
                'name' => 'idx_facility_settings_tenant',
            ])
            ->addForeignKey('facility_id', 'facilities', 'id', [
                'delete' => 'CASCADE',
                'update' => 'RESTRICT',
                'constraint' => 'fk_facility_settings_facility',
            ])
            ->addForeignKey('tenant_id', 'tenants', 'id', [
                'delete' => 'CASCADE',
                'update' => 'RESTRICT',
                'constraint' => 'fk_facility_settings_tenant',
            ])
            ->create();
    }

    public function down(): void
    {
        $this->table('facility_settings')->drop()->save();
    }
}
