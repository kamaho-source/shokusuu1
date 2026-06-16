<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\TableRegistry;

/**
 * 部屋使用率集計サービス
 *
 * 使用率 = 食べる件数 / (期間中に部屋に存在したユーザー数 × 日数 × 食種数) × 100
 *
 * 分母の「ユーザー数」は t_individual_reservation_info の DISTINCT ユーザー数を使う。
 * - m_user_group の active_flag（現在状態）ではなく実績ベースのため
 *   退出済みユーザーの eat_count が capacity を超える問題が発生しない。
 * - 複数部屋所属ユーザーは各部屋の DISTINCT ユーザー集合に独立して含まれる。
 * - 未提出の日がある場合も「1件でも記録がある = その部屋の利用者」とみなし、
 *   提出漏れ分は食べない扱いとして分母に算入する。
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

        $table = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $query = $table->find()
            ->contain(['MRoomInfo', 'MUserInfo'])
            ->where([
                'TIndividualReservationInfo.d_reservation_date >=' => $resolvedFrom,
                'TIndividualReservationInfo.d_reservation_date <=' => $resolvedTo,
            ]);

        if ($mealType !== null) {
            $query->where(['TIndividualReservationInfo.i_reservation_type' => $mealType]);
        }

        // 部屋ごとに「期間中の DISTINCT ユーザー集合」と「食べる件数」を集計
        $usersByRoom    = [];
        $eatCounts      = [];
        $roomNames      = [];
        $staffByRoom    = [];
        $staffEatCounts = [];
        $staffNames     = [];

        foreach ($query->all()->toArray() as $row) {
            $roomId    = (int)$row->i_id_room;
            $userId    = (int)$row->i_id_user;
            $userLevel = (int)($row->m_user_info->i_user_level ?? -1);

            $usersByRoom[$roomId][$userId] = true;
            $roomNames[$roomId]            = $row->m_room_info->c_room_name ?? '';

            $effectiveEat = $row->i_change_flag !== null
                ? (int)$row->i_change_flag
                : (int)($row->eat_flag ?? 0);
            if ($effectiveEat === 1) {
                $eatCounts[$roomId] = ($eatCounts[$roomId] ?? 0) + 1;
            }

            // 職員（i_user_level=0）のみ別途集計
            if ($userLevel === 0) {
                $staffByRoom[$roomId][$userId] = true;
                $staffNames[$userId]           = $row->m_user_info->c_user_name ?? '';
                if ($effectiveEat === 1) {
                    $staffEatCounts[$roomId][$userId] = ($staffEatCounts[$roomId][$userId] ?? 0) + 1;
                }
            }
        }

        $result = [];
        foreach ($usersByRoom as $roomId => $users) {
            $userCount = count($users);
            $capacity  = $userCount * $days * $mealTypeCount;
            $eatCount  = $eatCounts[$roomId] ?? 0;

            $staffList = [];
            foreach (array_keys($staffByRoom[$roomId] ?? []) as $staffId) {
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
