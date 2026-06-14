<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\TableRegistry;

/**
 * 部屋使用率集計サービス
 *
 * 分母: 対象期間に実際に存在する予約レコード数（食べる＋食べない）
 * 分子: そのうち effective_eat_flag = 1 のレコード数
 *
 * m_user_group の active_flag ではなく実レコードを分母にすることで
 * 「期間中に退出したユーザーの記録が eat_count に含まれる」問題を防ぎ、
 * 複数部屋所属ユーザーも部屋ごとに正しく集計できる。
 */
class RoomUsageService
{
    /**
     * 部屋ごとの使用率一覧を返す。
     *
     * @param string|null $dateFrom 開始日 (Y-m-d)
     * @param string|null $dateTo   終了日 (Y-m-d)
     * @param int|null    $mealType 食種 (1=朝 2=昼 3=夕 4=弁当, null=全て)
     * @return array{room_id:int, room_name:string, total_slots:int, eat_count:int, usage_rate:float}[]
     */
    public function getRoomUsage(
        ?string $dateFrom = null,
        ?string $dateTo   = null,
        ?int    $mealType = null
    ): array {
        $resolvedFrom = $dateFrom ?? date('Y-m-01');
        $resolvedTo   = $dateTo   ?? date('Y-m-d');

        $table = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $query = $table->find()
            ->contain(['MRoomInfo'])
            ->where([
                'TIndividualReservationInfo.d_reservation_date >=' => $resolvedFrom,
                'TIndividualReservationInfo.d_reservation_date <=' => $resolvedTo,
            ]);

        if ($mealType !== null) {
            $query->where(['TIndividualReservationInfo.i_reservation_type' => $mealType]);
        }

        $grouped = [];
        foreach ($query->all()->toArray() as $row) {
            $roomId = (int)$row->i_id_room;
            if (!isset($grouped[$roomId])) {
                $grouped[$roomId] = [
                    'room_id'     => $roomId,
                    'room_name'   => $row->m_room_info->c_room_name ?? '',
                    'total_slots' => 0,
                    'eat_count'   => 0,
                ];
            }
            $grouped[$roomId]['total_slots']++;
            $effectiveEat = $row->i_change_flag !== null
                ? (int)$row->i_change_flag
                : (int)($row->eat_flag ?? 0);
            if ($effectiveEat === 1) {
                $grouped[$roomId]['eat_count']++;
            }
        }

        $result = [];
        foreach ($grouped as $data) {
            $data['usage_rate'] = $data['total_slots'] > 0
                ? round($data['eat_count'] / $data['total_slots'] * 100, 1)
                : 0.0;
            $result[] = $data;
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
