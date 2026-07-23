<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

class AddReportAccessToMUserInfo extends AbstractMigration
{
    public function change(): void
    {
        $this->table('m_user_info')
            ->addColumn('i_report_access', 'integer', [
                'limit'   => 1,
                'null'    => false,
                'default' => 0,
                'comment' => 'システムレポート閲覧権限 (1=許可 0=不許可)',
                'after'   => 'i_admin',
            ])
            ->update();
    }
}
