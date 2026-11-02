<?php
declare(strict_types=1);

namespace App\Domain\Service;

/**
 * 利用者一覧に出す所属部屋の表示。
 */
final class RoomAffiliationLabel
{
    /**
     * @param list<string> $roomNames 所属部屋名の一覧
     * @param int $totalActiveRoomCount 削除されていない全部屋の数
     * @return string 未所属なら「未所属」、全部屋に所属していれば「全部屋所属」、それ以外は部屋名のカンマ区切り
     */
    public static function build(array $roomNames, int $totalActiveRoomCount): string
    {
        $uniqueNames = array_values(array_unique($roomNames));
        if ($uniqueNames === []) {
            return '未所属';
        }
        if ($totalActiveRoomCount > 0 && count($uniqueNames) >= $totalActiveRoomCount) {
            return '全部屋所属';
        }

        return implode(', ', $uniqueNames);
    }
}
