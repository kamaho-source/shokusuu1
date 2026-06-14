<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\TableRegistry;

/**
 * 部屋使用率集計サービス
 *
 * 使用率の分母は m_user_group の有効所属人数（定員ベース）。
 * 複数部屋所属ユーザーはそれぞれの部屋の定員に含まれ、
 * 実際に食べた部屋のみ eat_count にカウントされる。
 *
 * 使用率 = 食べる件数 / (部屋の有効所属人数 × 日数 × 食種数) × 100
 */
class RoomUsageService
{
    /**
     * 部屋ごとの使用率一覧を返す。
     *
     * @param string|null $dateFrom   開始日 (Y-m-d)
     * @param string|null $dateTo     終了日 (Y-m-d)
     * @param int|null    $mealType   食種 (1=朝 2=昼 3=夕 4=弁当, null=全て)
     * @return array{room_id:int, room_name:string, capacity:int, eat_count:int, usage_rate:float}[]
     */
    public function getRoomUsage(
        ?string $dateFrom = null,
        ?string $dateTo   = null,
        ?int    $mealType = null
    ): array {
        $resolvedFrom = $dateFrom ?? date('Y-m-01');
        $resolvedTo   = $dateTo   ?? date('Y-m-d');

        // 日数・食種数から1部屋あたりのスロット乗数を算出
        $days          = (int)(new \DateTimeImmutable($resolvedFrom))->diff(new \DateTimeImmutable($resolvedTo))->days + 1;
        $mealTypeCount = $mealType !== null ? 1 : 4;
        $slotMultiple  = $days * $mealTypeCount;

        // m_user_group から有効所属人数を部屋別に集計
        $groupTable = TableRegistry::getTableLocator()->get('MUserGroup');
        $groupRows  = $groupTable->find()
            ->contain(['MRoomInfo'])
            ->where(['active_flag' => 1])
            ->all()
            ->toArray();

        $roomCapacity = [];
        $roomNames    = [];
        foreach ($groupRows as $row) {
            $roomId = (int)$row->i_id_room;
            $roomCapacity[$roomId] = ($roomCapacity[$roomId] ?? 0) + 1;
            $roomNames[$roomId]    = $row->m_room_info->c_room_name ?? '';
        }

        // t_individual_reservation_info から食べる件数を部屋別に集計
        $resTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $query    = $resTable->find()
            ->where([
                'TIndividualReservationInfo.d_reservation_date >=' => $resolvedFrom,
                'TIndividualReservationInfo.d_reservation_date <=' => $resolvedTo,
            ]);

        if ($mealType !== null) {
            $query->where(['TIndividualReservationInfo.i_reservation_type' => $mealType]);
        }

        $eatCounts = [];
        foreach ($query->all()->toArray() as $row) {
            $roomId       = (int)$row->i_id_room;
            $effectiveEat = $row->i_change_flag !== null
                ? (int)$row->i_change_flag
                : (int)($row->eat_flag ?? 0);
            if ($effectiveEat === 1) {
                $eatCounts[$roomId] = ($eatCounts[$roomId] ?? 0) + 1;
            }
        }

        // 部屋ごとに使用率を算出
        $result = [];
        foreach ($roomCapacity as $roomId => $userCount) {
            $capacity = $userCount * $slotMultiple;
            $eatCount = $eatCounts[$roomId] ?? 0;
            $result[] = [
                'room_id'    => $roomId,
                'room_name'  => $roomNames[$roomId],
                'capacity'   => $capacity,
                'eat_count'  => $eatCount,
                'usage_rate' => $capacity > 0 ? round($eatCount / $capacity * 100, 1) : 0.0,
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
