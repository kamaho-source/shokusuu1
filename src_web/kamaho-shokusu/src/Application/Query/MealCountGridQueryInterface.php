<?php
declare(strict_types=1);

namespace App\Application\Query;

use App\Application\Query\Dto\MealCountGrid;
use App\Application\Query\Dto\MealGridUser;

/**
 * エクセル食数予約の読み取り。
 */
interface MealCountGridQueryInterface
{
    /**
     * 部屋ごとの所属中の利用者（削除済みを除く。表示順）。利用者のいない部屋も空の一覧を持つ。
     *
     * @param list<int> $roomIds
     * @return array<int, list<MealGridUser>> 部屋ID => 利用者
     */
    public function getRoomUsers(array $roomIds): array;

    /**
     * 表を組み立てる。食べるかの判定は、境界日以前は i_change_flag（NULL なら eat_flag）、それより先は eat_flag。
     *
     * @param array<int, string> $rooms 部屋ID => 部屋名
     * @param array<int, list<MealGridUser>> $roomUsers 部屋ID => 行に出す利用者
     * @param list<string> $dates YYYY-MM-DD
     */
    public function getGrid(array $rooms, array $roomUsers, array $dates): MealCountGrid;

    /**
     * 個人表示で選べる利用者（削除済みを除く。名前順）。
     *
     * @return array<int, string> 利用者ID => 名前
     */
    public function getSelectableUsers(): array;
}
