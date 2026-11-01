<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * m_user_group に「利用者の削除で外した所属」の印（i_left_by_deletion）を追加する。
 *
 * active_flag=1（退出済み）には、部屋異動で外れた行と、利用者の削除で外した行が混ざり、
 * 復元のときに「削除の直前に所属していた部屋」を見分けられなかった（#936）。
 * 削除で外した行に 1 を立て、復元ではその行だけを所属中に戻す。既存の行は NULL のまま（書き換えない）。
 * 列の有無を確かめてから追加・削除する（他の番号で適用済みの環境でも二重に追加しない）。
 */
final class AddLeftByDeletionToMUserGroup extends AbstractMigration
{
    /**
     * @throws \RuntimeException マイグレーション実行に失敗した場合
     */
    public function up(): void
    {
        $table = $this->table('m_user_group');
        if ($table->hasColumn('i_left_by_deletion')) {
            return;
        }
        $table
            ->addColumn('i_left_by_deletion', 'integer', [
                'limit'   => 1,
                'null'    => true,
                'default' => null,
                'comment' => '利用者の削除で外した所属なら 1（復元で所属中に戻す）。それ以外は NULL',
                'after'   => 'active_flag',
            ])
            ->update();
    }

    /**
     * @throws \RuntimeException マイグレーション実行に失敗した場合
     */
    public function down(): void
    {
        $table = $this->table('m_user_group');
        if (!$table->hasColumn('i_left_by_deletion')) {
            return;
        }
        $table->removeColumn('i_left_by_deletion')->update();
    }
}
