<?php
declare(strict_types=1);

namespace App\Application\Query;

use App\Application\Query\Dto\RoomTransferView;

/**
 * 部屋異動の画面の読み取り（一覧と、登録フォームの選択肢）。
 */
interface RoomTransferQueryInterface
{
    /**
     * 異動の予約（異動日の新しい順）。
     *
     * @param int|null $status 0・1・2 で絞る（null は全部）
     * @return list<RoomTransferView>
     */
    public function schedules(?int $status): array;

    /**
     * 異動させる人の選択肢（削除されていない人。表示順）。
     *
     * @return array<int, string> 利用者ID => 名前
     */
    public function selectableUsers(): array;

    /**
     * 部屋の選択肢（削除されていない部屋）。
     *
     * @return array<int, string> 部屋ID => 部屋名
     */
    public function selectableRooms(): array;

    /**
     * 利用者ごとの今の所属部屋（異動元の選択肢を絞るため）。
     *
     * @return array<int, list<int>> 利用者ID => 部屋ID
     */
    public function activeRoomsByUser(): array;
}
