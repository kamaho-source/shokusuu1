<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\TableRegistry;

/**
 * システムレポート集計サービス
 *
 * 部屋別・日別の子供/大人の予約数・使用率を集計する。
 * - 子供: i_user_level = 1
 * - 大人: i_user_level ≠ 1 (0, 7 等)
 * - 予約数: 有効フラグ（eat_flag or i_change_flag）が 1 の件数
 * - 使用率: 予約数 / (ユーザー数 × 日数 × 食種数) × 100（子供・大人は独立して算出）
 * - active_flag = 0 (現役) のユーザーのみ集計対象
 *
 * Excel出力はフロントエンド（ExcelJS）が担当するため、このサービスはJSON用データのみ返す。
 */
class SystemReportService
{
    private const CHILD_LEVEL = 1;
    private const MEAL_TYPES  = 4; // 朝・昼・夕・弁当

    /**
     * 部屋別の子供/大人 予約数・使用率を返す。
     *
     * @param array<int> $excludeUserIds 集計から除外するユーザーIDリスト
     * @param string $dateFrom 開始日 (Y-m-d)
     * @param string $dateTo   終了日 (Y-m-d)
     * @return array<array{
     *   room_id:int, room_name:string,
     *   child_users:int, adult_users:int,
     *   child_reservations:int, adult_reservations:int,
     *   child_usage_rate:float, adult_usage_rate:float
     * }>
     */
    public function getRoomStats(array $excludeUserIds, string $dateFrom, string $dateTo): array
    {
        $userGroupTable = TableRegistry::getTableLocator()->get('MUserGroup');

        $masterRows = $userGroupTable->find()
            ->contain(['MUserInfo', 'MRoomInfo'])
            ->where(['MUserGroup.active_flag' => 0])
            ->all()
            ->toArray();

        $rooms          = [];
        $userLevelInRoom = []; // [roomId][userId] = isChild(bool)

        foreach ($masterRows as $row) {
            $roomId    = (int)$row->i_id_room;
            $userId    = (int)$row->i_id_user;
            $userLevel = (int)($row->m_user_info?->i_user_level ?? 0);
            $roomName  = $row->m_room_info?->c_room_name ?? '';

            if (in_array($userId, $excludeUserIds, true)) {
                continue;
            }

            if (!isset($rooms[$roomId])) {
                $rooms[$roomId] = [
                    'room_id'             => $roomId,
                    'room_name'           => $roomName,
                    'child_users'         => 0,
                    'adult_users'         => 0,
                    'child_reservations'  => 0,
                    'adult_reservations'  => 0,
                    'child_usage_rate'    => 0.0,
                    'adult_usage_rate'    => 0.0,
                ];
            }

            $isChild = ($userLevel === self::CHILD_LEVEL);
            $userLevelInRoom[$roomId][$userId] = $isChild;

            if ($isChild) {
                $rooms[$roomId]['child_users']++;
            } else {
                $rooms[$roomId]['adult_users']++;
            }
        }

        if (empty($rooms)) {
            return [];
        }

        $days = max(1, (int)(new \DateTimeImmutable($dateFrom))->diff(new \DateTimeImmutable($dateTo))->days + 1);

        $reservTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $reservRows  = $reservTable->find()
            ->select(['i_id_user', 'i_id_room', 'eat_flag', 'i_change_flag', 'd_reservation_date'])
            ->where([
                'd_reservation_date >=' => $dateFrom,
                'd_reservation_date <=' => $dateTo,
            ])
            ->all();

        $today  = new \DateTimeImmutable('today');
        $cutoff = $today->modify('+14 days');

        foreach ($reservRows as $row) {
            $roomId = (int)$row->i_id_room;
            $userId = (int)$row->i_id_user;

            if (!isset($userLevelInRoom[$roomId][$userId])) {
                continue;
            }

            $reservDate    = new \DateTimeImmutable((string)$row->d_reservation_date);
            $isLastMinute  = ($reservDate >= $today && $reservDate <= $cutoff);
            $effectiveFlag = $isLastMinute && $row->i_change_flag !== null
                ? (int)$row->i_change_flag
                : (int)($row->eat_flag ?? 0);

            if ($effectiveFlag !== 1) {
                continue;
            }

            if ($userLevelInRoom[$roomId][$userId]) {
                $rooms[$roomId]['child_reservations']++;
            } else {
                $rooms[$roomId]['adult_reservations']++;
            }
        }

        foreach ($rooms as &$room) {
            $childCapacity = $room['child_users'] * $days * self::MEAL_TYPES;
            $adultCapacity = $room['adult_users'] * $days * self::MEAL_TYPES;

            $room['child_usage_rate'] = $childCapacity > 0
                ? round($room['child_reservations'] / $childCapacity * 100, 1) : 0.0;
            $room['adult_usage_rate'] = $adultCapacity > 0
                ? round($room['adult_reservations'] / $adultCapacity * 100, 1) : 0.0;
        }
        unset($room);

        $result = array_values($rooms);
        usort($result, static fn(array $a, array $b): int => strcmp($a['room_name'], $b['room_name']));

        return $result;
    }

    /**
     * 日別の子供/大人 予約件数を返す（ログイン総数 日別）。
     *
     * @param array<int> $excludeUserIds
     * @param string $dateFrom
     * @param string $dateTo
     * @return array<array{date:string, child_count:int, adult_count:int, total:int}>
     */
    public function getDailyStats(array $excludeUserIds, string $dateFrom, string $dateTo): array
    {
        $userInfoTable = TableRegistry::getTableLocator()->get('MUserInfo');
        $userRows      = $userInfoTable->find()
            ->select(['i_id_user', 'i_user_level'])
            ->where(['i_del_flag' => 0])
            ->all();

        $userLevelMap = []; // [userId => isChild]
        foreach ($userRows as $user) {
            $userId = (int)$user->i_id_user;
            if (in_array($userId, $excludeUserIds, true)) {
                continue;
            }
            $userLevelMap[$userId] = ((int)($user->i_user_level ?? 0)) === self::CHILD_LEVEL;
        }

        // 日付バケットを初期化
        $daily   = [];
        $current = new \DateTimeImmutable($dateFrom);
        $end     = new \DateTimeImmutable($dateTo);
        while ($current <= $end) {
            $daily[$current->format('Y-m-d')] = ['child' => 0, 'adult' => 0];
            $current = $current->modify('+1 day');
        }

        $reservTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $rows        = $reservTable->find()
            ->select(['i_id_user', 'd_reservation_date', 'eat_flag', 'i_change_flag'])
            ->where([
                'd_reservation_date >=' => $dateFrom,
                'd_reservation_date <=' => $dateTo,
            ])
            ->all();

        $today  = new \DateTimeImmutable('today');
        $cutoff = $today->modify('+14 days');

        foreach ($rows as $row) {
            $userId = (int)$row->i_id_user;
            if (!isset($userLevelMap[$userId])) {
                continue;
            }

            $dateStr = (string)$row->d_reservation_date;
            if (!isset($daily[$dateStr])) {
                continue;
            }

            $reservDate    = new \DateTimeImmutable($dateStr);
            $isLastMinute  = ($reservDate >= $today && $reservDate <= $cutoff);
            $effectiveFlag = $isLastMinute && $row->i_change_flag !== null
                ? (int)$row->i_change_flag
                : (int)($row->eat_flag ?? 0);

            if ($effectiveFlag !== 1) {
                continue;
            }

            if ($userLevelMap[$userId]) {
                $daily[$dateStr]['child']++;
            } else {
                $daily[$dateStr]['adult']++;
            }
        }

        return array_map(
            static fn(string $date, array $counts): array => [
                'date'        => $date,
                'child_count' => $counts['child'],
                'adult_count' => $counts['adult'],
                'total'       => $counts['child'] + $counts['adult'],
            ],
            array_keys($daily),
            array_values($daily)
        );
    }

    /**
     * 日別の子供予約件数のみを返す（日別子供総数ページ用）。
     *
     * @param array<int> $excludeUserIds
     * @param string $dateFrom
     * @param string $dateTo
     * @return array<array{date:string, child_count:int}>
     */
    public function getDailyChildrenStats(array $excludeUserIds, string $dateFrom, string $dateTo): array
    {
        $full = $this->getDailyStats($excludeUserIds, $dateFrom, $dateTo);

        return array_map(
            static fn(array $d): array => ['date' => $d['date'], 'child_count' => $d['child_count']],
            $full
        );
    }

    /**
     * ログイン情報を返す（TAuditLog ベース）。
     *
     * @param string $dateFrom
     * @param string $dateTo
     * @return array{daily:array<array{date:string,success:int,failed:int}>, logs:array<array{dt:string,user_name:string,login_id:string,result:int,ip:string}>}
     */
    public function getLoginStats(string $dateFrom, string $dateTo): array
    {
        $table = TableRegistry::getTableLocator()->get('TAuditLog');

        $rows = $table->find()
            ->select(['c_actor_user_name', 'c_actor_login_id', 'i_result', 'c_ip_address', 'dt_create'])
            ->where([
                'c_action IN'  => ['user_login', 'user_login_failed'],
                'dt_create >=' => $dateFrom . ' 00:00:00',
                'dt_create <=' => $dateTo . ' 23:59:59',
            ])
            ->orderBy(['dt_create' => 'DESC'])
            ->all();

        // 日付バケット初期化
        $daily   = [];
        $current = new \DateTimeImmutable($dateFrom);
        $end     = new \DateTimeImmutable($dateTo);
        while ($current <= $end) {
            $daily[$current->format('Y-m-d')] = ['success' => 0, 'failed' => 0];
            $current = $current->modify('+1 day');
        }

        $logs = [];
        foreach ($rows as $row) {
            $dt      = $row->dt_create instanceof \DateTimeInterface
                ? $row->dt_create
                : new \DateTimeImmutable((string)$row->dt_create);
            $dateStr = $dt->format('Y-m-d');
            $result  = (int)$row->i_result;

            if (isset($daily[$dateStr])) {
                if ($result === 1) {
                    $daily[$dateStr]['success']++;
                } else {
                    $daily[$dateStr]['failed']++;
                }
            }

            $logs[] = [
                'dt'        => $dt->format('Y-m-d H:i:s'),
                'user_name' => (string)($row->c_actor_user_name ?? ''),
                'login_id'  => (string)($row->c_actor_login_id ?? ''),
                'result'    => $result,
                'ip'        => (string)($row->c_ip_address ?? ''),
            ];
        }

        $dailyList = array_map(
            static fn(string $date, array $c): array => ['date' => $date, 'success' => $c['success'], 'failed' => $c['failed']],
            array_keys($daily),
            array_values($daily)
        );

        return ['daily' => $dailyList, 'logs' => $logs];
    }

    /**
     * 有効な全ユーザーを返す（除外候補選択UI用）。
     *
     * @return array<array{user_id:int, user_name:string, is_child:bool}>
     */
    public function getAllUsers(): array
    {
        $userInfoTable = TableRegistry::getTableLocator()->get('MUserInfo');
        $rows = $userInfoTable->find()
            ->select(['i_id_user', 'c_user_name', 'i_user_level'])
            ->where(['i_del_flag' => 0])
            ->orderBy(['c_user_name' => 'ASC'])
            ->all();

        $result = [];
        foreach ($rows->toArray() as $row) {
            $result[] = [
                'user_id'   => (int)$row->i_id_user,
                'user_name' => (string)($row->c_user_name ?? ''),
                'is_child'  => ((int)($row->i_user_level ?? 0)) === self::CHILD_LEVEL,
            ];
        }
        return $result;
    }
}
