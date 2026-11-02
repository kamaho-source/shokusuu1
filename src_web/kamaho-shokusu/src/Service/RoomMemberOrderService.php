<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\Query\SelectQuery;

/**
 * 部屋の所属メンバーの並び順（m_user_info.i_disp_no）で並べる。保存は UpdateRoomUseCase。
 */
class RoomMemberOrderService
{
    /**
     * 所属メンバーの並び（全画面共通）: i_disp_no（NULL は後ろ）→ 名前 → ID。区分（職員・児童・その他）では分けない。
     *
     * @param SelectQuery $query MUserInfo を $alias で結合（または主テーブルに）しているクエリ
     */
    public static function orderMembers(SelectQuery $query, string $alias = 'MUserInfo'): SelectQuery
    {
        return $query
            ->orderBy($query->expr("{$alias}.i_disp_no IS NULL"))
            ->orderBy([
                "{$alias}.i_disp_no"   => 'ASC',
                "{$alias}.c_user_name" => 'ASC',
                "{$alias}.i_id_user"   => 'ASC',
            ]);
    }
}
