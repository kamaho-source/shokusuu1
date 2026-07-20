<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * fiscal_year_update_date を date 型から char(5) 型へ変更する。
 *
 * 年度更新日は「毎年 MM 月 DD 日」という循環的な概念であり、
 * 特定の年を持つ date 型で保存する必要はない。
 * 'MM-DD' 形式の文字列として保存する。
 */
class ChangeFiscalYearUpdateDateToChar extends AbstractMigration
{
    public function up(): void
    {
        $this->table('facility_settings')
            ->changeColumn('fiscal_year_update_date', 'string', [
                'limit'   => 5,
                'null'    => true,
                'default' => null,
                'comment' => '年度更新日（MM-DD形式）',
            ])
            ->update();
    }

    public function down(): void
    {
        $this->table('facility_settings')
            ->changeColumn('fiscal_year_update_date', 'date', [
                'null'    => true,
                'default' => null,
                'comment' => '年度更新日',
            ])
            ->update();
    }
}
