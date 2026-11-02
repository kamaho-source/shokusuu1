<?php
declare(strict_types=1);

namespace App\Domain\Repository;

use App\Domain\Entity\Room;
use App\Domain\Entity\RoomSaveResult;

/**
 * 部屋（m_room_info）と、部屋の所属メンバーの並び順を読み書きする。
 *
 * DB の失敗（接続断など）は変換せずにそのまま投げる。
 */
interface RoomRepositoryInterface
{
    /** 部屋。居なければ null（削除済みも含めて探す）。 */
    public function find(int $roomId): ?Room;

    /** 新しく作る部屋の表示順（今の最大 + 1。部屋が無ければ 1）。 */
    public function nextDisplayNo(): int;

    /**
     * 部屋を新しく保存する。部屋名（c_room_name）はテーブルの入力チェックを通す。
     *
     * @param array<array-key, mixed> $input 送られた値（使うのは c_room_name だけ）
     * @param string|null $createdBy 作成者（ログインしていなければ null）
     * @param string $at 作成日時（'Y-m-d H:i:s'）
     */
    public function create(array $input, int $displayNo, ?string $createdBy, string $at): RoomSaveResult;

    /**
     * 部屋名を変える。送られた値に部屋名が無い・今と同じなら保存しない（changed=false）。入力の誤りがあれば保存せずに返す。
     *
     * @param array<array-key, mixed> $input 送られた値（使うのは c_room_name だけ）
     * @param string $at 更新日時（'Y-m-d H:i:s'）
     */
    public function rename(int $roomId, array $input, string $updatedBy, string $at): RoomSaveResult;

    /**
     * 部屋を削除済みにする（i_del_flg=1）。保存できなければ false。
     *
     * @param string $at 更新日時（'Y-m-d H:i:s'）
     */
    public function softDelete(int $roomId, ?string $updatedBy, string $at): bool;

    /**
     * 部屋の所属メンバー（所属中で、削除されていない人）の今の表示順。並びは画面と同じ。
     *
     * @return array<int, int|null> 利用者ID => i_disp_no
     */
    public function memberDisplayOrders(int $roomId): array;

    /** 全利用者の表示順の最大値（誰も居なければ 0）。 */
    public function maxUserDisplayNo(): int;

    /**
     * 利用者の表示順を変える。
     *
     * @param string $at 更新日時（'Y-m-d H:i:s'）
     */
    public function setUserDisplayNo(int $userId, int $displayNo, string $updatedBy, string $at): void;
}
