<?php
declare(strict_types=1);

use Migrations\AbstractMigration;

/**
 * m_room_transfer_schedule に適用失敗の理由（c_fail_reason）を追加する。
 *
 * 適用できない部屋異動（昼食と弁当の重なりなど）は、これまで予約中のまま残り、毎晩のバッチで同じ失敗を繰り返していた（#945）。
 * 失敗したら状態を「適用失敗」（i_status=3）にして理由を残し、管理者が一覧で見て、やり直すか取り消せるようにする。
 * 既存の行は NULL のまま（書き換えない）。列の有無を確かめてから追加・削除する（他の番号で適用済みの環境でも二重に追加しない）。
 */
final class AddFailReasonToMRoomTransferSchedule extends AbstractMigration
{
    /**
     * @throws \RuntimeException マイグレーション実行に失敗した場合
     */
    public function up(): void
    {
        $table = $this->table('m_room_transfer_schedule');
        if ($table->hasColumn('c_fail_reason')) {
            return;
        }
        $table
            ->addColumn('c_fail_reason', 'string', [
                'limit'   => 255,
                'null'    => true,
                'default' => null,
                'comment' => '適用失敗の理由（i_status=3 のときだけ）',
                'after'   => 'i_status',
            ])
            ->update();
    }

    /**
     * @throws \RuntimeException マイグレーション実行に失敗した場合
     */
    public function down(): void
    {
        $table = $this->table('m_room_transfer_schedule');
        if (!$table->hasColumn('c_fail_reason')) {
            return;
        }
        // 戻したあとのコードは「適用失敗」を知らないので、予約中に戻す（次のバッチで適用し直す）
        $this->execute('UPDATE m_room_transfer_schedule SET i_status = 0 WHERE i_status = 3');
        $table->removeColumn('c_fail_reason')->update();
    }
}
