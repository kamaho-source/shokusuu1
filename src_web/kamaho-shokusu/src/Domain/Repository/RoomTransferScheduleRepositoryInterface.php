<?php
declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\RoomTransferSchedule;

/**
 * 部屋異動の予約（m_room_transfer_schedule）を読み書きする。
 *
 * 保存の失敗は例外のまま投げる（従来どおり、画面ではその文言を出す）。
 */
interface RoomTransferScheduleRepositoryInterface
{
    /**
     * 予約中として登録する。値はテーブルの入力チェックを通し、誤りがあれば保存せずに理由を返す。
     *
     * @param array<string, mixed> $values i_id_user・i_id_room_from・i_id_room_to・d_effective_date
     * @param string $at 作成日時（'Y-m-d H:i:s'）
     * @return array<string, array<string, string>> 項目ごとの入力の誤り（保存できたら空）
     */
    public function create(array $values, string $createdBy, string $at): array;

    /** 予約。無ければ null。 */
    public function find(int $scheduleId): ?RoomTransferSchedule;

    /**
     * 状態を変える（取消・適用済み）。
     *
     * @param string $at 更新日時（'Y-m-d H:i:s'）
     */
    public function changeStatus(int $scheduleId, int $status, string $updatedBy, string $at): void;

    /**
     * 適用失敗にして理由を残す（更新者は 'batch'）。理由は列の長さ（255文字）に切る。
     * 状態を変える changeStatus では、理由を消す。
     *
     * @param string $at 更新日時（'Y-m-d H:i:s'）
     */
    public function markFailed(int $scheduleId, string $reason, string $at): void;

    /**
     * 有効開始日が基準日以前の、予約中の予約。
     *
     * @return list<RoomTransferSchedule>
     */
    public function pendingUntil(string $date): array;
}
