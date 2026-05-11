<?php
declare(strict_types=1);

namespace App\Service;

use Cake\I18n\Date;
use Cake\ORM\Table;

/**
 * MealCountGridService
 *
 * 食数予約Excelグリッド画面のデータ構築を担当するサービスクラス。
 *
 * 責務:
 *   - 表示日付範囲の生成（週単位）
 *   - 部屋×ユーザー×日付×食事種別のグリッドデータ構築
 *   - 部屋小計・日次合計の算出
 *
 * フラグ読み取りポリシー:
 *   - 14日以内: i_change_flag を優先（NULL の場合は eat_flag にフォールバック）
 *   - 14日超: eat_flag を使用
 */
class MealCountGridService
{
    /** 全食事種別（朝/昼/夕/弁）*/
    public const MEALS = [1 => '朝', 2 => '昼', 3 => '夕', 4 => '弁'];

    /** 管理者が遡れる最大月数 */
    private const ADMIN_LOOKBACK_MONTHS = 2;

    /** 非管理者が参照できる未来週数 */
    private const FUTURE_WEEKS = 4;

    /**
     * 指定月曜日から $days 日分の日付文字列を返す。
     *
     * @param string $mondayStr YYYY-MM-DD（月曜日）
     * @param int    $days      日数（デフォルト 28 = 4週間）
     * @return string[]
     */
    public function buildDateRange(string $mondayStr, int $days = 28): array
    {
        $base  = new \DateTimeImmutable($mondayStr);
        $dates = [];
        for ($i = 0; $i < $days; $i++) {
            $dates[] = $base->modify("+{$i} days")->format('Y-m-d');
        }
        return $dates;
    }

    /**
     * 期間表示ラベルを返す（例: 2025/11/10 〜 2025/12/07 (28日)）。
     *
     * @param string[] $dates
     * @return string
     */
    public function buildPeriodLabel(array $dates): string
    {
        if (empty($dates)) {
            return '';
        }
        $start = (new \DateTimeImmutable($dates[0]))->format('Y/m/d');
        $end   = (new \DateTimeImmutable($dates[count($dates) - 1]))->format('Y/m/d');
        $days  = count($dates);
        return "{$start} 〜 {$end} ({$days}日)";
    }

    /**
     * 食事種別ごとの月計（集計）を返す。
     *
     * @param array $gridData buildGrid() の返却値
     * @return array<int, int> [mealType => count]
     */
    public function buildMonthlyTotals(array $gridData): array
    {
        $totals = array_fill_keys(array_keys(self::MEALS), 0);
        foreach ($gridData['dailyTotals'] as $dateTotals) {
            foreach ($dateTotals as $mealType => $count) {
                $totals[$mealType] += (int)$count;
            }
        }
        return $totals;
    }

    /**
     * 管理者が遡れる最古週月曜日を返す。
     *
     * @param \DateTimeImmutable $today
     * @return \DateTimeImmutable
     */
    public function getAdminOldestAllowedMonday(\DateTimeImmutable $today): \DateTimeImmutable
    {
        $thisMonday = (int)$today->format('N') === 1
            ? $today
            : $today->modify('monday this week');

        $lookback = $thisMonday->modify('-' . self::ADMIN_LOOKBACK_MONTHS . ' months');

        // 月曜日に正規化（2ヶ月前が月曜以外の場合）
        return (int)$lookback->format('N') === 1
            ? $lookback
            : $lookback->modify('monday this week');
    }

    /**
     * 週パラメータを正規化し、ナビゲーション情報を返す。
     *
     * @param string|null        $weekParam URL クエリの week パラメータ（YYYY-MM-DD）
     * @param bool               $isAdmin   管理者フラグ
     * @param \DateTimeImmutable $today     現在日時（テスト注入用）
     * @return array{
     *   weekMonday: \DateTimeImmutable,
     *   weekMondayStr: string,
     *   prevMonday: \DateTimeImmutable,
     *   nextMonday: \DateTimeImmutable,
     *   canGoPrev: bool,
     *   canGoNext: bool,
     * }
     */
    public function resolveWeekNavigation(?string $weekParam, bool $isAdmin, \DateTimeImmutable $today): array
    {
        $thisMonday = (int)$today->format('N') === 1
            ? $today
            : $today->modify('monday this week');

        $oldestMonday = $isAdmin
            ? $this->getAdminOldestAllowedMonday($today)
            : $thisMonday;

        $futureMonday = $thisMonday->modify('+' . self::FUTURE_WEEKS . ' weeks');

        // パラメータを月曜日に正規化
        $weekMonday = $thisMonday;
        if ($weekParam !== null) {
            try {
                $dt = new \DateTimeImmutable($weekParam, new \DateTimeZone('Asia/Tokyo'));
                $weekMonday = (int)$dt->format('N') === 1
                    ? $dt
                    : $dt->modify('monday this week');
            } catch (\Throwable) {
                $weekMonday = $thisMonday;
            }
        }

        // 非管理者は今週より前に戻れない
        if (!$isAdmin && $weekMonday < $thisMonday) {
            $weekMonday = $thisMonday;
        }
        // 最古週より前はクランプ
        if ($weekMonday < $oldestMonday) {
            $weekMonday = $oldestMonday;
        }
        // 未来上限を超えたらクランプ
        if ($weekMonday > $futureMonday) {
            $weekMonday = $futureMonday;
        }

        $prevMonday = $weekMonday->modify('-7 days');
        $nextMonday = $weekMonday->modify('+7 days');

        return [
            'weekMonday'    => $weekMonday,
            'weekMondayStr' => $weekMonday->format('Y-m-d'),
            'prevMonday'    => $prevMonday,
            'nextMonday'    => $nextMonday,
            'canGoPrev'     => $prevMonday >= $oldestMonday,
            'canGoNext'     => $nextMonday <= $futureMonday,
        ];
    }

    /**
     * 指定部屋のアクティブユーザー一覧を取得する。
     *
     * @param Table $userGroupTable m_user_group テーブル
     * @param Table $userInfoTable  m_user_info テーブル
     * @param int   $roomId
     * @return array<int, array{id:int, name:string}>
     */
    public function getRoomUsers(Table $userGroupTable, Table $userInfoTable, int $roomId): array
    {
        $rows = $userGroupTable->find()
            ->enableAutoFields(false)
            ->select([
                'i_id_user' => 'MUserGroup.i_id_user',
                'user_name'  => 'MUserInfo.c_user_name',
            ])
            ->innerJoin(
                ['MUserInfo' => $userInfoTable->getTable()],
                ['MUserInfo.i_id_user = MUserGroup.i_id_user']
            )
            ->where([
                'MUserGroup.i_id_room'   => $roomId,
                'MUserGroup.active_flag' => 0,
                'MUserInfo.i_del_flag'   => 0,
            ])
            ->enableHydration(false)
            ->orderAsc('MUserInfo.c_user_name')
            ->all();

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id'   => (int)$row['i_id_user'],
                'name' => (string)$row['user_name'],
            ];
        }
        return $result;
    }

    /**
     * グリッドデータを構築する。
     *
     * 返却構造:
     * [
     *   'dates' => ['2026-05-11', ...],
     *   'meals' => [1 => '朝', 2 => '昼', 3 => '夕', 4 => '弁'],
     *   'rooms' => [
     *     roomId => [
     *       'name'  => '部屋名',
     *       'users' => [{id, name}],
     *       'grid'  => [userId => [date => [mealType => bool]]],
     *     ]
     *   ],
     *   'dailyTotals' => [date => [mealType => int]],
     * ]
     *
     * @param Table    $reservationTable TIndividualReservationInfo テーブル
     * @param array    $rooms            [roomId => roomName]
     * @param array    $roomUsers        [roomId => [{id, name}]]
     * @param string[] $dates            YYYY-MM-DD の配列
     * @return array
     */
    public function buildGrid(
        Table $reservationTable,
        array $rooms,
        array $roomUsers,
        array $dates
    ): array {
        $today      = Date::today('Asia/Tokyo');
        $borderDate = $today->addDays(14);

        $allUserIds = [];
        foreach ($roomUsers as $users) {
            foreach ($users as $u) {
                $allUserIds[] = (int)$u['id'];
            }
        }
        $allUserIds = array_values(array_unique($allUserIds));

        // 対象期間のレコードを一括取得
        $mealTypes = array_keys(self::MEALS);
        $rows = [];
        if (!empty($allUserIds) && !empty($dates)) {
            $rows = $reservationTable->find()
                ->enableAutoFields(false)
                ->select([
                    'i_id_user',
                    'i_id_room',
                    'd_reservation_date',
                    'i_reservation_type',
                    'eat_flag',
                    'i_change_flag',
                ])
                ->where([
                    'i_id_user IN'          => $allUserIds,
                    'd_reservation_date IN' => $dates,
                    'i_reservation_type IN' => $mealTypes,
                ])
                ->enableHydration(false)
                ->all()
                ->toArray();
        }

        // (userId, roomId, date, mealType) → effective フラグ
        $map = [];
        foreach ($rows as $row) {
            $uid    = (int)$row['i_id_user'];
            $rid    = (int)$row['i_id_room'];
            $date   = $this->normalizeDateString($row['d_reservation_date']);
            $type   = (int)$row['i_reservation_type'];
            $change = $row['i_change_flag'];
            $eat    = $row['eat_flag'];

            $dateObj = new Date($date);
            if ($dateObj <= $borderDate) {
                $effective = $change !== null ? (int)$change : (int)($eat ?? 0);
            } else {
                $effective = (int)($eat ?? 0);
            }

            $map[$uid][$rid][$date][$type] = $effective === 1;
        }

        // グリッド組み立て
        $roomsData   = [];
        $dailyTotals = [];

        foreach ($dates as $date) {
            $dailyTotals[$date] = array_fill_keys($mealTypes, 0);
        }

        foreach ($rooms as $roomId => $roomName) {
            $roomId = (int)$roomId;
            $users  = $roomUsers[$roomId] ?? [];

            $grid = [];
            foreach ($users as $u) {
                $uid = (int)$u['id'];
                $grid[$uid] = [];
                foreach ($dates as $date) {
                    $grid[$uid][$date] = [];
                    foreach ($mealTypes as $mealType) {
                        $on = $map[$uid][$roomId][$date][$mealType] ?? false;
                        $grid[$uid][$date][$mealType] = $on;
                        if ($on) {
                            $dailyTotals[$date][$mealType]++;
                        }
                    }
                }
            }

            $roomsData[$roomId] = [
                'name'  => $roomName,
                'users' => $users,
                'grid'  => $grid,
            ];
        }

        return [
            'dates'       => $dates,
            'meals'       => self::MEALS,
            'rooms'       => $roomsData,
            'dailyTotals' => $dailyTotals,
        ];
    }

    /**
     * @param mixed $value
     */
    private function normalizeDateString($value): string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        return (string)$value;
    }
}
