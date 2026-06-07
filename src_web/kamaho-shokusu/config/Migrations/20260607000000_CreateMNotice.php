<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

class CreateMNotice extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('m_notice')) {
            return;
        }

        $table = $this->table('m_notice', ['id' => false, 'primary_key' => ['i_id']]);
        $table
            ->addColumn('i_id', 'integer', ['identity' => true, 'null' => false])
            ->addColumn('c_title', 'string', ['null' => false, 'limit' => 200])
            ->addColumn('c_body', 'text', ['null' => true, 'default' => null])
            ->addColumn('d_start', 'date', ['null' => true, 'default' => null, 'comment' => '掲示開始日（nullは即時掲示）'])
            ->addColumn('d_end', 'date', ['null' => true, 'default' => null, 'comment' => '掲示終了日（nullは無期限）'])
            ->addColumn('i_importance', 'integer', ['null' => false, 'default' => 0, 'limit' => 1, 'comment' => '0=通常, 1=重要'])
            ->addColumn('i_id_user_created', 'integer', ['null' => true, 'default' => null])
            ->addColumn('c_create_user', 'string', ['null' => true, 'limit' => 50])
            ->addColumn('dt_create', 'datetime', ['null' => true])
            ->addColumn('c_update_user', 'string', ['null' => true, 'limit' => 50])
            ->addColumn('dt_update', 'datetime', ['null' => true])
            ->addIndex(['d_start', 'd_end'], ['name' => 'idx_notice_dates'])
            ->addIndex(['i_importance'], ['name' => 'idx_notice_importance'])
            ->create();
    }

    public function down(): void
    {
        $this->table('m_notice')->drop()->save();
    }
}
