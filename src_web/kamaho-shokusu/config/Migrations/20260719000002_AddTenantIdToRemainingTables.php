<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * テナントIDが未付与だった業務テーブルへ tenant_id / facility_id を追加する。
 *
 * 対象テーブル:
 *   - m_notice            : お知らせ（i_type=0 はテナント固有、i_type=1 は全体通知）
 *   - t_data_import_history: インポート履歴
 *   - m_menu_info         : メニューマスタ
 *   - t_menu_plan         : 献立計画
 *   - t_menu_plan_detail  : 献立計画明細（facility_id のみ。メニューplanに紐づく）
 *   - t_menu_favorite     : メニューお気に入り
 *   - t_menu_rating       : メニュー評価
 *   - t_ai_generation_history: AI生成履歴
 *   - t_contacts          : お問い合わせ
 *   - t_contact_replies   : お問い合わせ返信
 */
class AddTenantIdToRemainingTables extends AbstractMigration
{
    public function up(): void
    {
        // m_notice
        $this->table('m_notice')
            ->addColumn('tenant_id', 'integer', [
                'null'    => true,
                'default' => null,
                'comment' => 'テナントID（i_type=0 のテナント固有お知らせに設定）',
                'after'   => 'i_id',
            ])
            ->addIndex(['tenant_id'])
            ->update();

        // t_data_import_history
        $this->table('t_data_import_history')
            ->addColumn('tenant_id', 'integer', [
                'null'    => true,
                'default' => null,
                'comment' => 'テナントID',
                'after'   => 'i_id_import',
            ])
            ->addColumn('facility_id', 'integer', [
                'null'    => true,
                'default' => null,
                'comment' => '施設ID',
                'after'   => 'tenant_id',
            ])
            ->addIndex(['tenant_id'])
            ->update();

        // m_menu_info
        $this->table('m_menu_info')
            ->addColumn('tenant_id', 'integer', [
                'null'    => true,
                'default' => null,
                'comment' => 'テナントID（NULL=全テナント共通メニュー）',
                'after'   => 'i_id_menu',
            ])
            ->addIndex(['tenant_id'])
            ->update();

        // t_menu_plan
        $this->table('t_menu_plan')
            ->addColumn('tenant_id', 'integer', [
                'null'    => true,
                'default' => null,
                'comment' => 'テナントID',
                'after'   => 'i_id_plan',
            ])
            ->addColumn('facility_id', 'integer', [
                'null'    => true,
                'default' => null,
                'comment' => '施設ID',
                'after'   => 'tenant_id',
            ])
            ->addIndex(['tenant_id'])
            ->update();

        // t_menu_plan_detail（planに紐づくため tenant_id は不要、facility_id のみ）
        $this->table('t_menu_plan_detail')
            ->addColumn('tenant_id', 'integer', [
                'null'    => true,
                'default' => null,
                'comment' => 'テナントID',
                'after'   => 'i_id_detail',
            ])
            ->update();

        // t_menu_favorite（ユーザーIDで絞れるが tenant_id も持つ）
        $this->table('t_menu_favorite')
            ->addColumn('tenant_id', 'integer', [
                'null'    => true,
                'default' => null,
                'comment' => 'テナントID',
                'after'   => 'i_id_favorite',
            ])
            ->update();

        // t_menu_rating
        $this->table('t_menu_rating')
            ->addColumn('tenant_id', 'integer', [
                'null'    => true,
                'default' => null,
                'comment' => 'テナントID',
                'after'   => 'i_id_rating',
            ])
            ->update();

        // t_ai_generation_history
        $this->table('t_ai_generation_history')
            ->addColumn('tenant_id', 'integer', [
                'null'    => true,
                'default' => null,
                'comment' => 'テナントID',
                'after'   => 'i_id_history',
            ])
            ->update();

        // t_contacts
        $this->table('t_contacts')
            ->addColumn('tenant_id', 'integer', [
                'null'    => true,
                'default' => null,
                'comment' => 'テナントID',
                'after'   => 'id',
            ])
            ->update();

        // t_contact_replies
        $this->table('t_contact_replies')
            ->addColumn('tenant_id', 'integer', [
                'null'    => true,
                'default' => null,
                'comment' => 'テナントID',
                'after'   => 'id',
            ])
            ->update();

        // 既存データをテナントID=1にバックフィル
        $tables = [
            'm_notice',
            't_data_import_history',
            'm_menu_info',
            't_menu_plan',
            't_menu_plan_detail',
            't_menu_favorite',
            't_menu_rating',
            't_ai_generation_history',
            't_contacts',
            't_contact_replies',
        ];

        foreach ($tables as $table) {
            $this->execute("UPDATE `{$table}` SET tenant_id = 1 WHERE tenant_id IS NULL");
        }

        // t_data_import_history と t_menu_plan は facility_id もバックフィル
        $this->execute("UPDATE t_data_import_history SET facility_id = 1 WHERE facility_id IS NULL");
        $this->execute("UPDATE t_menu_plan SET facility_id = 1 WHERE facility_id IS NULL");
    }

    public function down(): void
    {
        $this->table('m_notice')->removeColumn('tenant_id')->update();
        $this->table('t_data_import_history')->removeColumn('tenant_id')->removeColumn('facility_id')->update();
        $this->table('m_menu_info')->removeColumn('tenant_id')->update();
        $this->table('t_menu_plan')->removeColumn('tenant_id')->removeColumn('facility_id')->update();
        $this->table('t_menu_plan_detail')->removeColumn('tenant_id')->update();
        $this->table('t_menu_favorite')->removeColumn('tenant_id')->update();
        $this->table('t_menu_rating')->removeColumn('tenant_id')->update();
        $this->table('t_ai_generation_history')->removeColumn('tenant_id')->update();
        $this->table('t_contacts')->removeColumn('tenant_id')->update();
        $this->table('t_contact_replies')->removeColumn('tenant_id')->update();
    }
}
