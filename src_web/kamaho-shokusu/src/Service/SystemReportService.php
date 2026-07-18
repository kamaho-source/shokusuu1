<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\TableRegistry;

/**
 * システムレポート集計サービス
 *
 * ユーザーごとの予約数・使用数を集計する。
 * - 予約数: 有効フラグ（eat_flag or i_change_flag）が 1 の件数
 * - 使用数: i_approval_status >= 1（何らかの承認済み）の件数
 *
 * Excel出力はフロントエンド（SheetJS）が担当するため、このサービスはJSON用データのみ返す。
 */
class SystemReportService
{
    /**
     * ユーザー別集計データを返す。
     *
     * @param array<int> $excludeUserIds 集計から除外するユーザーIDリスト
     * @param string $dateFrom 開始日 (Y-m-d)
     * @param string $dateTo   終了日 (Y-m-d)
     * @return array<array{user_id:int, user_name:string, reservation_count:int, usage_count:int}>
     */
    public function getUserStats(array $excludeUserIds, string $dateFrom, string $dateTo): array
    {
        $userInfoTable = TableRegistry::getTableLocator()->get('MUserInfo');

        $userQuery = $userInfoTable->find()
            ->select(['i_id_user', 'c_user_name'])
            ->where(['i_del_flag' => 0, 'i_enable' => 1]);

        if (!empty($excludeUserIds)) {
            $userQuery->where(['i_id_user NOT IN' => $excludeUserIds]);
        }

        $users = [];
        foreach ($userQuery->all()->toArray() as $user) {
            $users[(int)$user->i_id_user] = [
                'user_id'           => (int)$user->i_id_user,
                'user_name'         => (string)($user->c_user_name ?? ''),
                'reservation_count' => 0,
                'usage_count'       => 0,
            ];
        }

        if (empty($users)) {
            return [];
        }

        $reservationTable = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $rows = $reservationTable->find()
            ->select([
                'i_id_user',
                'eat_flag',
                'i_change_flag',
                'i_approval_status',
                'd_reservation_date',
            ])
            ->where([
                'd_reservation_date >=' => $dateFrom,
                'd_reservation_date <=' => $dateTo,
            ])
            ->all();

        $today  = new \DateTimeImmutable('today');
        $cutoff = $today->modify('+14 days');

        foreach ($rows as $row) {
            $userId = (int)$row->i_id_user;
            if (!isset($users[$userId])) {
                continue;
            }

            // 有効値の判定（直前14日以内は i_change_flag 優先）
            $reservDate   = new \DateTimeImmutable((string)$row->d_reservation_date);
            $isLastMinute = ($reservDate >= $today && $reservDate <= $cutoff);

            $effectiveFlag = $isLastMinute && $row->i_change_flag !== null
                ? (int)$row->i_change_flag
                : (int)($row->eat_flag ?? 0);

            if ($effectiveFlag === 1) {
                $users[$userId]['reservation_count']++;
            }

            if ((int)($row->i_approval_status ?? 0) >= 1) {
                $users[$userId]['usage_count']++;
            }
        }

        $result = array_values($users);
        usort($result, static fn(array $a, array $b): int => strcmp($a['user_name'], $b['user_name']));

        return $result;
    }

    /**
     * 有効な全ユーザーを返す（除外候補選択UI用）。
     *
     * @return array<array{user_id:int, user_name:string}>
     */
    public function getAllUsers(): array
    {
        $userInfoTable = TableRegistry::getTableLocator()->get('MUserInfo');
        $rows = $userInfoTable->find()
            ->select(['i_id_user', 'c_user_name'])
            ->where(['i_del_flag' => 0, 'i_enable' => 1])
            ->orderBy(['c_user_name' => 'ASC'])
            ->all();

        $result = [];
        foreach ($rows->toArray() as $row) {
            $result[] = [
                'user_id'   => (int)$row->i_id_user,
                'user_name' => (string)($row->c_user_name ?? ''),
            ];
        }
        return $result;
    }
}
