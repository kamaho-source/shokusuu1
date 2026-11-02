<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\TableRegistry;

/**
 * 部屋管理サービス
 *
 * 部屋の表示用データの集約を担う（作成・編集・削除は UseCase）。
 */
class RoomService
{
    /**
     * 部屋に紐づくユーザー一覧を返す。
     *
     * 異動で抜けた所属（active_flag ≠ 0）のユーザーは含めない。
     *
     * @param \App\Infrastructure\Persistence\Entity\MRoomInfo $roomInfo MUserGroup を contain 済みのエンティティ
     * @return array
     */
    public function getUsersForRoom(\App\Infrastructure\Persistence\Entity\MRoomInfo $roomInfo): array
    {
        $userGroups = array_filter(
            $roomInfo->m_user_group ?: [],
            fn($group) => (int)$group->active_flag === 0
        );

        $userIds = array_map(
            fn($group) => $group->i_id_user,
            $userGroups
        );

        if (empty($userIds)) {
            return [];
        }

        $table = TableRegistry::getTableLocator()->get('MUserInfo');
        return RoomMemberOrderService::orderMembers($table->find())
            ->where(['MUserInfo.i_id_user IN' => $userIds, 'MUserInfo.i_del_flag' => 0])
            ->toArray();
    }


    /**
     * 有効な部屋数（i_del_flg = 0）を返す。
     */
    public function countActiveRooms(): int
    {
        return TableRegistry::getTableLocator()->get('MRoomInfo')
            ->find()
            ->where(['i_del_flg' => 0])
            ->count();
    }

    /**
     * 所属部屋の表示ラベルを組み立てる。
     *
     * @param list<string> $roomNames 所属部屋名の一覧
     * @param int $totalActiveRoomCount 有効な全部屋数
     * @return string 未所属時は「未所属」、全部屋に所属している場合は「全部屋所属」、それ以外は部屋名のカンマ区切り
     */
    public function buildAffiliationLabel(array $roomNames, int $totalActiveRoomCount): string
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
