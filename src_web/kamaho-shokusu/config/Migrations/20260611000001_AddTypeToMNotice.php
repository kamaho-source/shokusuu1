<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

class AddTypeToMNotice extends AbstractMigration
{
    public function up(): void
    {
        $table = $this->table('m_notice');

        if ($table->hasColumn('i_type')) {
            return;
        }

        $table
            ->addColumn('i_type', 'integer', [
                'null'    => false,
                'default' => 0,
                'limit'   => 1,
                'comment' => '0=通常, 1=リリースノート',
                'after'   => 'i_importance',
            ])
            ->addIndex(['i_type'], ['name' => 'idx_notice_type'])
            ->update();
    }

    public function down(): void
    {
        $this->table('m_notice')->removeColumn('i_type')->update();
    }
}
