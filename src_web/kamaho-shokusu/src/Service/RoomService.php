<?php
declare(strict_types=1);

namespace App\Service;

use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;

/**
 * 部屋管理サービス
 *
 * 部屋の表示用データ集約・採番・ソフトデリートを担う。
 */
class RoomService
{
    /**
     * 所属行の一覧から、現在の所属部屋の名前だけを返す。
     *
     * ユーザーの詳細・編集画面と同じ基準:
     * 異動で抜けた所属（active_flag ≠ 0）、削除済みの部屋（i_del_flg ≠ 0。NULL も削除扱い）は含めない。
     *
     * @param iterable<\App\Model\Entity\MUserGroup> $groups MRoomInfo を contain 済みの所属行
     * @return list<string>
     */
    public function currentRoomNames(iterable $groups): array
    {
        $names = [];
        foreach ($groups as $group) {
            if ((int)$group->active_flag !== 0 || empty($group->m_room_info) || (int)($group->m_room_info->i_del_flg ?? 1) !== 0) {
                continue;
            }
            $names[] = (string)$group->m_room_info->c_room_name;
        }

        return $names;
    }

    /**
     * 部屋に紐づくユーザー一覧を返す。
     *
     * 異動で抜けた所属（active_flag ≠ 0）のユーザーは含めない。
     *
     * @param \App\Model\Entity\MRoomInfo $roomInfo MUserGroup を contain 済みのエンティティ
     * @return array
     */
    public function getUsersForRoom(\App\Model\Entity\MRoomInfo $roomInfo): array
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
        return $table->find('all', [
            'conditions' => ['MUserInfo.i_id_user IN' => $userIds],
        ])->toArray();
    }

    /**
     * 次の表示番号（MAX + 1）を返す。
     */
    public function nextDisplayNo(): int
    {
        $table   = TableRegistry::getTableLocator()->get('MRoomInfo');
        $maxDispNo = (int)($table->find()
            ->select(['max_disp_no' => 'MAX(i_disp_no)'])
            ->first()
            ?->max_disp_no ?? 0);

        return $maxDispNo + 1;
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

    /**
     * 部屋をソフトデリートする（i_del_flg = 1）。
     *
     * @param \App\Model\Entity\MRoomInfo $roomInfo
     * @param string|null                 $updatedBy 更新者名
     * @return bool
     */
    public function softDelete(\App\Model\Entity\MRoomInfo $roomInfo, ?string $updatedBy): bool
    {
        $table = TableRegistry::getTableLocator()->get('MRoomInfo');

        $roomInfo->i_del_flg    = 1;
        $roomInfo->c_update_user = $updatedBy;
        $roomInfo->dt_update    = DateTime::now('Asia/Tokyo');

        return (bool)$table->save($roomInfo);
    }
}
