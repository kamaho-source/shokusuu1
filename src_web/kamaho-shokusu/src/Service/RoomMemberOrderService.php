<?php
declare(strict_types=1);

namespace App\Service;

use App\Domain\Exception\InvalidInputException;
use App\Domain\Service\MemberDisplayOrder;
use Cake\I18n\DateTime;
use Cake\ORM\Query\SelectQuery;
use Cake\ORM\TableRegistry;

/**
 * 部屋の所属メンバーの並び順（m_user_info.i_disp_no）の取得と保存。
 */
class RoomMemberOrderService
{
    /**
     * 所属メンバーの並び（全画面共通）: i_user_level → i_disp_no（NULL は後ろ）→ 名前 → ID。
     *
     * @param SelectQuery $query MUserInfo を $alias で結合（または主テーブルに）しているクエリ
     */
    public static function orderMembers(SelectQuery $query, string $alias = 'MUserInfo'): SelectQuery
    {
        return $query
            ->orderBy(["{$alias}.i_user_level" => 'ASC'])
            ->orderBy($query->expr("{$alias}.i_disp_no IS NULL"))
            ->orderBy([
                "{$alias}.i_disp_no"   => 'ASC',
                "{$alias}.c_user_name" => 'ASC',
                "{$alias}.i_id_user"   => 'ASC',
            ]);
    }

    /**
     * 部屋の所属メンバー全員を、画面で並べた順に保存する。並びは同じ i_user_level の中で効く。
     *
     * @param list<int> $userIds 並べた順のユーザーID（部屋の所属メンバー全員）
     * @return int 番号が変わった人数
     * @throws InvalidInputException 顔ぶれが今の所属メンバーと一致しないとき
     */
    public function reorder(int $roomId, array $userIds, string $updateUser): int
    {
        $userTable = TableRegistry::getTableLocator()->get('MUserInfo');

        return $userTable->getConnection()->transactional(function () use ($userTable, $roomId, $userIds, $updateUser): int {
            $members = self::orderMembers(
                TableRegistry::getTableLocator()->get('MUserGroup')->find()
                    ->enableAutoFields(false)
                    ->select([
                        'id'      => 'MUserInfo.i_id_user',
                        'level'   => 'MUserInfo.i_user_level',
                        'disp_no' => 'MUserInfo.i_disp_no',
                    ])
                    ->innerJoin(['MUserInfo' => 'm_user_info'], ['MUserInfo.i_id_user = MUserGroup.i_id_user'])
                    ->where([
                        'MUserGroup.i_id_room'   => $roomId,
                        'MUserGroup.active_flag' => 0,
                        'MUserInfo.i_del_flag'   => 0,
                    ])
                    ->enableHydration(false)
            )->toArray();

            if (count($userIds) !== count(array_unique($userIds)) || count($userIds) !== count($members)) {
                throw new InvalidInputException('部屋の所属メンバーが変わっています。画面を読み込み直してください。');
            }

            $groups = [];
            foreach ($members as $m) {
                $groups[(int)($m['level'] ?? -1)][(int)$m['id']] = $m['disp_no'] === null ? null : (int)$m['disp_no'];
            }

            $maxDispNo = (int)($userTable->find()->select(['m' => 'MAX(i_disp_no)'])->enableHydration(false)->first()['m'] ?? 0);
            $changes = [];
            foreach ($groups as $current) {
                // 送られた順のうち、このグループの人だけを取り出す（グループをまたぐ並びは使わない）
                $newOrder = array_values(array_intersect($userIds, array_keys($current)));
                $assigned = MemberDisplayOrder::reassign($current, $newOrder, $maxDispNo);
                $maxDispNo = max([$maxDispNo, ...array_values($assigned)]);
                $changes += $assigned;
            }

            $now = DateTime::now('Asia/Tokyo');
            foreach ($changes as $userId => $dispNo) {
                $userTable->updateAll(
                    ['i_disp_no' => $dispNo, 'dt_update' => $now, 'c_update_user' => $updateUser],
                    ['i_id_user' => $userId]
                );
            }

            return count($changes);
        });
    }
}
