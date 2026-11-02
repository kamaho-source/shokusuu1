<?php
declare(strict_types=1);

namespace App\Application\Query;

use App\Application\Query\Dto\RoomUsage;

/**
 * 部屋使用率の読み取り。
 *
 * 使用率 = 食べる件数 ÷（部屋の在籍者数 × 日数 × 食事の数）× 100（MealUsageRate）。
 * 在籍者は所属中（active_flag=0）で削除されていない人。1度も入力していない人も分母に入る。部屋は削除されていない部屋。
 * 複数の部屋に所属する人は、それぞれの部屋に入る。食べる件数は、その部屋に在籍する人の、その部屋の予約だけ数える
 * （ReservationDateRule::countedValue が 1）。
 */
interface RoomUsageQueryInterface
{
    /**
     * 部屋ごとの使用率（部屋名の順）。職員（i_user_level 0・7）の個別の使用率も含む。
     *
     * @param int|null $mealType 食事（1〜4。null は全食事）
     * @param int|null $level i_user_level で絞る（null は全員）
     * @return list<RoomUsage>
     */
    public function roomUsage(string $dateFrom, string $dateTo, ?int $mealType = null, ?int $level = null): array;
}
