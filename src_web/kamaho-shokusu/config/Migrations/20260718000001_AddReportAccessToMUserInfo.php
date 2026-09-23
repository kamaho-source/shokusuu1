<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * m_user_info にシステムレポート閲覧権限カラムを追加する。
 */
final class AddReportAccessToMUserInfo extends AbstractMigration
{
    /**
     * @throws \RuntimeException マイグレーション実行に失敗した場合
     */
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
