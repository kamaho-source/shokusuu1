<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

class CreateMLpImage extends AbstractMigration
{
    public function up(): void
    {
        if ($this->hasTable('m_lp_image')) {
            return;
        }

        $table = $this->table('m_lp_image', ['id' => false, 'primary_key' => ['i_id']]);
        $table
            ->addColumn('i_id', 'integer', ['identity' => true, 'null' => false])
            ->addColumn('c_title', 'string', ['null' => false, 'limit' => 100, 'comment' => '画像タイトル（LPのキャプションに使用）'])
            ->addColumn('c_section', 'string', ['null' => false, 'limit' => 20, 'default' => 'gallery', 'comment' => 'hero=ヒーロー画像, gallery=導入イメージ'])
            ->addColumn('c_file_path', 'string', ['null' => false, 'limit' => 255, 'comment' => 'webroot からの相対パス（img/lp/uploads/...）'])
            ->addColumn('i_display', 'integer', ['null' => false, 'default' => 1, 'limit' => 1, 'comment' => '1=LPに表示, 0=非表示'])
            ->addColumn('i_sort', 'integer', ['null' => false, 'default' => 0, 'comment' => '表示順（昇順）'])
            ->addColumn('dt_create', 'datetime', ['null' => true])
            ->addColumn('dt_update', 'datetime', ['null' => true])
            ->addIndex(['c_section', 'i_display', 'i_sort'], ['name' => 'idx_lp_image_display'])
            ->create();
    }

    public function down(): void
    {
        $this->table('m_lp_image')->drop()->save();
    }
}
