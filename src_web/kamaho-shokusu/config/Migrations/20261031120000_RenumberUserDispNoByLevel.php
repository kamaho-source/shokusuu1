<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * m_user_info.i_disp_no を、今の画面の並び（区分 → 表示順 → 名前 → ID）のまま 1 から振り直す（#871）。
 *
 * 並びから区分（i_user_level）を外し、表示順だけで並べるようにした。i_disp_no はこれまで登録順の番号だったため、
 * そのままだと全画面で職員と児童が登録順に混ざる。振り直して、区分を外した後も今と同じ並びから始める。
 */
final class RenumberUserDispNoByLevel extends AbstractMigration
{
    /**
     * @throws \RuntimeException マイグレーション実行に失敗した場合
     */
    public function up(): void
    {
        // 並べ方は RoomMemberOrderService::orderMembers の区分あり版（MySQL の ASC は NULL が先）
        $rows = $this->fetchAll(
            'SELECT i_id_user FROM m_user_info
             ORDER BY i_user_level, i_disp_no IS NULL, i_disp_no, c_user_name, i_id_user'
        );
        $no = 0;
        foreach ($rows as $row) {
            $this->execute('UPDATE m_user_info SET i_disp_no = ? WHERE i_id_user = ?', [++$no, (int)$row['i_id_user']]);
        }
    }

    /**
     * 振り直す前の番号は残していないため、戻さない。
     */
    public function down(): void
    {
    }
}
