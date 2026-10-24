<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * m_user_info にシステムレポート閲覧権限カラムを追加する。
 *
 * 旧バージョン番号 20260718000001 は CreateTenants（#563）と重複しており、
 * 適用済み扱いでスキップされていたため番号を振り直した。
 * 旧番号で適用済みの環境でも重複追加しないよう、カラムの有無を確認する。
 */
final class AddReportAccessToMUserInfo extends AbstractMigration
{
    /**
     * @throws \RuntimeException マイグレーション実行に失敗した場合
     */
    public function up(): void
    {
        $table = $this->table('m_user_info');
        if ($table->hasColumn('i_report_access')) {
            return;
        }
        $table
            ->addColumn('i_report_access', 'integer', [
                'limit'   => 1,
                'null'    => false,
                'default' => 0,
                'comment' => 'システムレポート閲覧権限 (1=許可 0=不許可)',
                'after'   => 'i_admin',
            ])
            ->update();
    }

    /**
     * @throws \RuntimeException マイグレーション実行に失敗した場合
     */
    public function down(): void
    {
        $table = $this->table('m_user_info');
        if (!$table->hasColumn('i_report_access')) {
            return;
        }
        $table->removeColumn('i_report_access')->update();
    }
}
