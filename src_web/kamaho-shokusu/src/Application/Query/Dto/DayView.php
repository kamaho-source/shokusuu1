<?php
declare(strict_types=1);

namespace App\Application\Query\Dto;

/**
 * 日別の食数状況の画面に出す内容。
 */
final class DayView
{
    /**
     * @param string $date 'Y-m-d'
     * @param array<int, string> $authorizedRooms 選べる部屋（所属していて削除されていない部屋）
     * @param int $activeRoomId 表示している部屋（選べる部屋が無ければ 0）
     * @param list<DayViewUser> $users 表示している部屋の利用者（表示順）
     * @param array<int, array<int, true>> $userMeals 利用者ID => 食事 => この部屋で食べる
     * @param array<int, array<int, string>> $otherRoomMeals 利用者ID => 食事 => 食べる別の部屋の名前
     */
    public function __construct(
        public readonly string $date,
        public readonly array $authorizedRooms,
        public readonly int $activeRoomId,
        public readonly string $activeRoomName,
        public readonly array $users,
        public readonly array $userMeals,
        public readonly array $otherRoomMeals,
    ) {}
}
