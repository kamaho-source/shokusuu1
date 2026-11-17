<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

class AddModalToMNotice extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('m_notice');

        if ($table->hasColumn('i_modal')) {
            return;
        }

        $table
            ->addColumn('i_modal', 'integer', [
                'null'    => false,
                'default' => 0,
                'limit'   => 1,
                'comment' => '0=カードのみ, 1=モーダルでも表示',
                'after'   => 'i_type',
            ])
            ->update();
    }

    public function down(): void
    {
        $this->table('m_notice')->removeColumn('i_modal')->update();
    }
}
