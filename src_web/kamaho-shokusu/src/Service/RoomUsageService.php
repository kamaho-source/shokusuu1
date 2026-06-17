<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\TableRegistry;

/**
 * 部屋使用率集計サービス
 *
 * 使用率 = 食べる件数 / (部屋の登録ユーザー数 × 日数 × 食種数) × 100
 *
 * 分母の「ユーザー数」は m_user_group.active_flag=0（現役）の登録ユーザー数を使う。
 * - 1度も入力していない入居者も分母に算入されるため、実績ベースより正確。
 * - 退出済みユーザー（active_flag=1）は分母・分子ともに除外する。
 * - 複数部屋所属ユーザーは各部屋の登録集合に独立して含まれる。
 */
class RoomUsageService
{
    /**
     * 部屋ごとの使用率一覧を返す。職員（i_user_level=0）の個別使用率も含む。
     *
     * @param string|null $dateFrom 開始日 (Y-m-d)
     * @param string|null $dateTo   終了日 (Y-m-d)
     * @param int|null    $mealType 食種 (1=朝 2=昼 3=夕 4=弁当, null=全て)
     * @return array{room_id:int, room_name:string, user_count:int, capacity:int, eat_count:int, usage_rate:float, staff:array}[]
     */
    public function getRoomUsage(
        ?string $dateFrom = null,
        ?string $dateTo   = null,
        ?int    $mealType = null
    ): array {
        $resolvedFrom  = $dateFrom ?? date('Y-m-01');
        $resolvedTo    = $dateTo   ?? date('Y-m-d');
        $days          = (int)(new \DateTimeImmutable($resolvedFrom))->diff(new \DateTimeImmutable($resolvedTo))->days + 1;
        $mealTypeCount = $mealType !== null ? 1 : 4;

        // m_user_group から active_flag=0（現役）の登録ユーザーを部屋ごとに取得（分母）
        $userGroupTable = TableRegistry::getTableLocator()->get('MUserGroup');
        $masterRows = $userGroupTable->find()
            ->contain(['MUserInfo', 'MRoomInfo'])
            ->where(['MUserGroup.active_flag' => 0])
            ->all()
            ->toArray();

        $masterUsers = [];  // [roomId][userId] = true
        $roomNames   = [];
        $staffMaster = [];  // [roomId][userId] = true  職員のみ
        $staffNames  = [];

        foreach ($masterRows as $row) {
            $roomId    = (int)$row->i_id_room;
            $userId    = (int)$row->i_id_user;
            $userLevel = (int)($row->m_user_info->i_user_level ?? -1);

            $masterUsers[$roomId][$userId] = true;
            $roomNames[$roomId]            = $row->m_room_info->c_room_name ?? '';

            if ($userLevel === 0) {
                $staffMaster[$roomId][$userId] = true;
                $staffNames[$userId]           = $row->m_user_info->c_user_name ?? '';
            }
        }

        if (empty($masterUsers)) {
            return [];
        }

        // 実績テーブルから eat_count を集計（active なユーザーの分のみ）
        $table = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $query = $table->find()
            ->where([
                'TIndividualReservationInfo.d_reservation_date >=' => $resolvedFrom,
                'TIndividualReservationInfo.d_reservation_date <=' => $resolvedTo,
            ]);

        if ($mealType !== null) {
            $query->where(['TIndividualReservationInfo.i_reservation_type' => $mealType]);
        }

        $eatCounts      = [];
        $staffEatCounts = [];

        foreach ($query->all()->toArray() as $row) {
            $roomId = (int)$row->i_id_room;
            $userId = (int)$row->i_id_user;

            // マスターに存在しないユーザー（退出済み: active_flag=1 等）はスキップ
            if (!isset($masterUsers[$roomId][$userId])) {
                continue;
            }

            $effectiveEat = $row->i_change_flag !== null
                ? (int)$row->i_change_flag
                : (int)($row->eat_flag ?? 0);

            if ($effectiveEat === 1) {
                $eatCounts[$roomId] = ($eatCounts[$roomId] ?? 0) + 1;

                if (isset($staffMaster[$roomId][$userId])) {
                    $staffEatCounts[$roomId][$userId] = ($staffEatCounts[$roomId][$userId] ?? 0) + 1;
                }
            }
        }

        $result = [];
        foreach ($masterUsers as $roomId => $users) {
            $userCount = count($users);
            $capacity  = $userCount * $days * $mealTypeCount;
            $eatCount  = $eatCounts[$roomId] ?? 0;

            $staffList = [];
            foreach (array_keys($staffMaster[$roomId] ?? []) as $staffId) {
                $staffCapacity = $days * $mealTypeCount;
                $staffEatCount = $staffEatCounts[$roomId][$staffId] ?? 0;
                $staffList[]   = [
                    'user_id'    => $staffId,
                    'user_name'  => $staffNames[$staffId],
                    'capacity'   => $staffCapacity,
                    'eat_count'  => $staffEatCount,
                    'usage_rate' => $staffCapacity > 0 ? round($staffEatCount / $staffCapacity * 100, 1) : 0.0,
                ];
            }
            usort($staffList, static fn(array $a, array $b): int => strcmp((string)$a['user_name'], (string)$b['user_name']));

            $result[] = [
                'room_id'    => $roomId,
                'room_name'  => $roomNames[$roomId],
                'user_count' => $userCount,
                'capacity'   => $capacity,
                'eat_count'  => $eatCount,
                'usage_rate' => $capacity > 0 ? round($eatCount / $capacity * 100, 1) : 0.0,
                'staff'      => $staffList,
            ];
        }

        usort($result, static fn(array $a, array $b): int => strcmp((string)$a['room_name'], (string)$b['room_name']));

        return $result;
    }

    /**
     * 使用率が閾値以下の部屋を返す。
     *
     * @param float       $threshold 使用率の閾値（%）。この値以下の部屋を返す。デフォルト 50.0
     * @param string|null $dateFrom
     * @param string|null $dateTo
     * @param int|null    $mealType
     * @return array
     */
    public function getLowUsageRooms(
        float   $threshold = 50.0,
        ?string $dateFrom  = null,
        ?string $dateTo    = null,
        ?int    $mealType  = null
    ): array {
        $all = $this->getRoomUsage($dateFrom, $dateTo, $mealType);

        return array_values(
            array_filter($all, static fn(array $r): bool => $r['usage_rate'] <= $threshold)
        );
    }
}
