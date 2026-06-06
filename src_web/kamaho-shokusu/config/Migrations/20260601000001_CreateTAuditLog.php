<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

class CreateTAuditLog extends AbstractMigration
{
    public function change(): void
    {
        $table = $this->table('t_audit_log', ['id' => false, 'primary_key' => 'i_id_audit']);

        $table->addColumn('i_id_audit', 'biginteger', [
            'autoIncrement' => true,
            'null' => false,
        ]);
        $table->addColumn('c_category', 'string', [
            'limit' => 50,
            'null' => false,
            'comment' => 'カテゴリ',
        ]);
        $table->addColumn('c_action', 'string', [
            'limit' => 100,
            'null' => false,
            'comment' => '操作種別',
        ]);
        $table->addColumn('c_target_table', 'string', [
            'limit' => 100,
            'null' => true,
            'default' => null,
            'comment' => '対象テーブル',
        ]);
        $table->addColumn('c_target_id', 'string', [
            'limit' => 255,
            'null' => true,
            'default' => null,
            'comment' => '対象レコードID',
        ]);
        $table->addColumn('i_actor_user_id', 'integer', [
            'null' => true,
            'default' => null,
            'comment' => '操作者ユーザーID',
        ]);
        $table->addColumn('c_actor_user_name', 'string', [
            'limit' => 50,
            'null' => false,
            'comment' => '操作者ユーザー名',
        ]);
        $table->addColumn('c_ip_address', 'string', [
            'limit' => 45,
            'null' => true,
            'default' => null,
            'comment' => '操作元IPアドレス',
        ]);
        $table->addColumn('i_result', 'boolean', [
            'null' => false,
            'default' => 1,
            'comment' => '1:成功 0:失敗',
        ]);
        $table->addColumn('c_detail', 'text', [
            'null' => true,
            'default' => null,
            'comment' => '操作詳細（JSON）',
        ]);
        $table->addColumn('dt_create', 'datetime', [
            'null' => false,
            'comment' => '操作日時',
        ]);

        $table->addIndex(['c_category'],                           ['name' => 'idx_category']);
        $table->addIndex(['c_action'],                             ['name' => 'idx_action']);
        $table->addIndex(['i_actor_user_id'],                      ['name' => 'idx_actor']);
        $table->addIndex(['dt_create'],                            ['name' => 'idx_dt_create']);
        $table->addIndex(['c_target_table', 'c_target_id'],        ['name' => 'idx_target']);

        $table->create();
    }
}
