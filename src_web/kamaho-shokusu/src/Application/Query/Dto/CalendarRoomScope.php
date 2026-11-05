<?php
declare(strict_types=1);

namespace App\Application\Query\Dto;

/**
 * 予約カレンダー（一覧画面）の部屋の範囲。管理者も所属部屋のみで、全部屋の集計は事務所ユーザーだけ（#821）。
 */
final class CalendarRoomScope
{
    /**
     * @param list<int> $userRoomIds 有効な所属部屋（退出済み・削除済みを除く）
     * @param int|null $userRoomId 既定の部屋（有効な所属部屋の先頭）
     * @param array<int, string> $rooms 集計欄で選べる部屋（事務所ユーザーは所属する事務所の部屋）
     * @param array<int, string> $pickerRooms 予約ポップアップで選べる部屋（役割を問わず有効な所属部屋）
     * @param int|null $calRoomId 集計する部屋（null は集計範囲の全体）
     * @param list<int> $roomFilter 食数を集計する部屋
     */
    public function __construct(
        public readonly array $userRoomIds,
        public readonly ?int $userRoomId,
        public readonly array $rooms,
        public readonly array $pickerRooms,
        public readonly ?int $calRoomId,
        public readonly array $roomFilter,
    ) {}
}
