<?php
declare(strict_types=1);

namespace App\Application\Query\Dto;

/**
 * 直前編集（1部屋 × 1日）の画面の中身。
 */
final class ChangeEditContext
{
    /**
     * @param list<ChangeEditMember> $members 部屋の利用者（所属中・削除されていない人。表示順）
     * @param array<int, array<int, array{room_id: int, eat_flag: int, room_name: string, i_change_flag: int|null}>> $reservations 利用者ID => 食事 => その日の予約（どの部屋か）
     */
    public function __construct(
        public readonly int $roomId,
        public readonly string $roomName,
        public readonly array $members,
        public readonly array $reservations,
    ) {}

    /**
     * @return list<int>
     */
    public function memberIds(): array
    {
        return array_map(static fn(ChangeEditMember $m): int => $m->id, $this->members);
    }
}
