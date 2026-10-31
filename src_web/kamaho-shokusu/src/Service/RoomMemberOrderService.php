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

    /**
     * 部屋の所属メンバー全員を、画面で並べた順に保存する（区分をまたいで並べてよい）。
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

            $current = [];
            foreach ($members as $m) {
                $current[(int)$m['id']] = $m['disp_no'] === null ? null : (int)$m['disp_no'];
            }

            $maxDispNo = (int)($userTable->find()->select(['m' => 'MAX(i_disp_no)'])->enableHydration(false)->first()['m'] ?? 0);
            $changes = MemberDisplayOrder::reassign($current, $userIds, $maxDispNo);

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
