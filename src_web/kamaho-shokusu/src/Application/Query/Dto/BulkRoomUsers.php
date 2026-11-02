<?php
declare(strict_types=1);

namespace App\Application\Query\Dto;

/**
 * 一括登録の画面に出す部屋の利用者（1ページ分）と、その日の予約。
 */
final class BulkRoomUsers
{
    /**
     * @param list<BulkRoomUser> $users
     * @param array<int, array<int, true>> $reservations 利用者ID => 食事 => この部屋で食べる
     * @param array<int, array<int, int>> $otherRoomReservations 利用者ID => 食事 => 食べる別の部屋の ID
     * @param int $total 部屋の利用者の総数
     * @param string|null $snapshot その日・部屋の最終更新日時（楽観的ロック用）
     */
    public function __construct(
        public readonly array $users,
        public readonly array $reservations,
        public readonly array $otherRoomReservations,
        public readonly int $total,
        public readonly int $page,
        public readonly int $limit,
        public readonly ?string $snapshot,
    ) {}

    /**
     * API の応答の形（従来のキー）。
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'users'                   => array_map(static fn(BulkRoomUser $u) => $u->toArray(), $this->users),
            'reservations'            => $this->reservations,
            'other_room_reservations' => $this->otherRoomReservations,
            'total'                   => $this->total,
            'page'                    => $this->page,
            'limit'                   => $this->limit,
            'reservation_snapshot'    => $this->snapshot,
        ];
    }
}
