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
     * 部屋に紐づくユーザー一覧を返す。
     *
     * @param \App\Model\Entity\MRoomInfo $roomInfo MUserGroup を contain 済みのエンティティ
     * @return array
     */
    public function getUsersForRoom(mixed $roomInfo): array
    {
        $userGroups = $roomInfo->m_user_group ?: [];

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
     * 部屋をソフトデリートする（i_del_flg = 1）。
     *
     * @param \App\Model\Entity\MRoomInfo $roomInfo
     * @param string|null                 $updatedBy 更新者名
     * @return bool
     */
    public function softDelete(mixed $roomInfo, ?string $updatedBy): bool
    {
        $table = TableRegistry::getTableLocator()->get('MRoomInfo');

        $roomInfo->i_del_flg    = 1;
        $roomInfo->c_update_user = $updatedBy;
        $roomInfo->dt_update    = DateTime::now('Asia/Tokyo');

        return (bool)$table->save($roomInfo);
    }
}
