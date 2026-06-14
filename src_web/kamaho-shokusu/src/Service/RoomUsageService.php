<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\TableRegistry;

/**
 * 部屋使用率集計サービス
 *
 * t_individual_reservation_info を集計し、部屋ごとの使用率を算出する。
 * 使用率 = 食べる人数 / 総予約数 × 100
 */
class RoomUsageService
{
    /**
     * 部屋ごとの使用率一覧を返す。
     *
     * @param string|null $dateFrom 開始日 (Y-m-d)
     * @param string|null $dateTo   終了日 (Y-m-d)
     * @param int|null    $mealType 食種 (1=朝 2=昼 3=夕 4=弁当, null=全て)
     * @return array{room_id:int, room_name:string, total_reservations:int, eat_count:int, usage_rate:float}[]
     */
    public function getRoomUsage(
        ?string $dateFrom = null,
        ?string $dateTo   = null,
        ?int    $mealType = null
    ): array {
        $table = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $query = $table->find()->contain(['MRoomInfo']);

        if ($dateFrom !== null) {
            $query->where(['TIndividualReservationInfo.d_reservation_date >=' => $dateFrom]);
        }
        if ($dateTo !== null) {
            $query->where(['TIndividualReservationInfo.d_reservation_date <=' => $dateTo]);
        }
        if ($mealType !== null) {
            $query->where(['TIndividualReservationInfo.i_reservation_type' => $mealType]);
        }

        $rows = $query->all()->toArray();

        $grouped = [];
        foreach ($rows as $row) {
            $roomId = (int)$row->i_id_room;
            if (!isset($grouped[$roomId])) {
                $grouped[$roomId] = [
                    'room_id'            => $roomId,
                    'room_name'          => $row->m_room_info->c_room_name ?? '',
                    'total_reservations' => 0,
                    'eat_count'          => 0,
                ];
            }
            $grouped[$roomId]['total_reservations']++;
            $effectiveEat = $row->i_change_flag !== null
                ? (int)$row->i_change_flag
                : (int)($row->eat_flag ?? 0);
            if ($effectiveEat === 1) {
                $grouped[$roomId]['eat_count']++;
            }
        }

        $result = [];
        foreach ($grouped as $data) {
            $data['usage_rate'] = $data['total_reservations'] > 0
                ? round($data['eat_count'] / $data['total_reservations'] * 100, 1)
                : 0.0;
            $result[] = $data;
        }

        usort($result, static fn(array $a, array $b): int => strcmp((string)$a['room_name'], (string)$b['room_name']));

        return $result;
    }

    /**
     * 使用率が閾値を下回る部屋を返す。
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
