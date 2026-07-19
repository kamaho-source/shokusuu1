<?php
declare(strict_types=1);

namespace App\Service;

use App\Application\Tenant\TenantContextHolder;
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
    public function getUsersForRoom(\App\Model\Entity\MRoomInfo $roomInfo): array
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
        $table = TableRegistry::getTableLocator()->get('MRoomInfo');
        $query = $table->find()->select(['max_disp_no' => 'MAX(i_disp_no)']);
        $ctx = TenantContextHolder::get();
        if ($ctx !== null) {
            $query->where(['tenant_id' => $ctx->tenantId()]);
        }
        $maxDispNo = (int)($query->first()?->max_disp_no ?? 0);

        return $maxDispNo + 1;
    }

    /**
     * 有効な部屋数（i_del_flg = 0）を返す。
     */
    public function countActiveRooms(): int
    {
        $query = TableRegistry::getTableLocator()->get('MRoomInfo')
            ->find()
            ->where(['i_del_flg' => 0]);
        $ctx = TenantContextHolder::get();
        if ($ctx !== null) {
            $query->where(['tenant_id' => $ctx->tenantId()]);
        }
        return $query->count();
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
