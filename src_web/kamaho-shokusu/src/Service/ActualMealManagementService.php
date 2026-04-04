<?php
declare(strict_types=1);

namespace App\Service;

use Cake\I18n\Date;
use Cake\I18n\DateTime;
use Cake\ORM\Table;

/**
 * ActualMealManagementService
 *
 * 実食確認管理（大人限定）機能のビジネスロジックを担当するサービスクラス。
 *
 * 責務:
 *   - 大人ユーザー（i_id_staff を保持するユーザー）の取得
 *   - 週単位の実食グリッドデータ構築（i_change_flag を参照）
 *   - 実食実績の保存（i_change_flag の更新）
 *
 * ControlHeader ロジック:
 *   - 今日 <= 14日以内: i_change_flag を実効値として使用
 *   - 今日 > 14日先: eat_flag を実効値として使用
 */
class ActualMealManagementService
{
    /** 管理者が遡れる最大月数 */
    private const ADMIN_LOOKBACK_MONTHS = 2;

    /**
     * 指定部屋の大人ユーザー（i_id_staff を保持するユーザー）一覧を返す。
     *
     * @param Table $userGroupTable m_user_group テーブル
     * @param Table $userTable      m_user_info テーブル
     * @param int   $roomId         対象部屋ID
     * @return array<int, array{id:int, name:string, staff_id:string}>
     */
    public function getAdultUsers(Table $userGroupTable, Table $userTable, int $roomId): array
    {
        $rows = $userGroupTable->find()
            ->enableAutoFields(false)
            ->select([
                'i_id_user'  => 'MUserGroup.i_id_user',
                'user_name'  => 'MUserInfo.c_user_name',
                'staff_id'   => 'MUserInfo.i_id_staff',
            ])
            ->innerJoin(
                ['MUserInfo' => 'm_user_info'],
                ['MUserInfo.i_id_user = MUserGroup.i_id_user']
            )
            ->where([
                'MUserGroup.i_id_room'   => $roomId,
                'MUserGroup.active_flag' => 0,
                'MUserInfo.i_del_flag'   => 0,
            ])
            ->andWhere(['MUserInfo.i_id_staff IS NOT' => null])
            ->andWhere(['MUserInfo.i_id_staff !=' => ''])
            ->enableHydration(false)
            ->orderAsc('MUserInfo.c_user_name')
            ->all();

        $result = [];
        foreach ($rows as $row) {
            $result[] = [
                'id'       => (int)$row['i_id_user'],
                'name'     => (string)$row['user_name'],
                'staff_id' => (string)($row['staff_id'] ?? ''),
            ];
        }

        return $result;
    }

    /**
     * 週グリッドデータを構築する。
     *
     * 返却するグリッドの構造:
     *   [
     *     'dates'   => ['2026-03-02', '2026-03-03', ...],  // 月〜日の7日間
     *     'meals'   => [1 => '朝', 2 => '昼', 3 => '夜'],  // 食事タイプマップ
     *     'grid'    => [                                     // userId => date => mealType => bool
     *       userId => ['2026-03-02' => [1 => true, 2 => false, 3 => true], ...]
     *     ],
     *     'versions' => [userId => date => mealType => version],
     *   ]
     *
     * @param Table  $reservationTable TIndividualReservationInfo テーブル
     * @param array  $users            getAdultUsers() の返却値
     * @param string $weekMonday       週の月曜日 (YYYY-MM-DD)
     * @return array
     */
    public function buildWeekGrid(Table $reservationTable, array $users, string $weekMonday): array
    {
        $today = Date::today('Asia/Tokyo');

        // 月曜〜日曜の7日間の日付を生成する
        $baseDate = new \DateTimeImmutable($weekMonday);
        $dates = [];
        for ($i = 0; $i < 7; $i++) {
            $dates[] = $baseDate->modify("+{$i} days")->format('Y-m-d');
        }

        $meals = [1 => '朝', 2 => '昼', 3 => '夜'];

        if (empty($users)) {
            return [
                'dates'    => $dates,
                'meals'    => $meals,
                'grid'     => [],
                'versions' => [],
            ];
        }

        $userIds = array_column($users, 'id');

        // 当該週の予約レコードを取得する
        $rows = $reservationTable->find()
            ->enableAutoFields(false)
            ->select([
                'i_id_user',
                'd_reservation_date',
                'i_reservation_type',
                'eat_flag',
                'i_change_flag',
                'i_version',
            ])
            ->where([
                'i_id_user IN'           => $userIds,
                'd_reservation_date IN'  => $dates,
                'i_reservation_type IN'  => [1, 2, 3],
            ])
            ->enableHydration(false)
            ->all();

        // (userId, date, mealType) => row のマップを構築する
        $map = [];
        foreach ($rows as $row) {
            $uid  = (int)$row['i_id_user'];
            $date = $this->normalizeDateString($row['d_reservation_date']);
            if ($date === null) {
                continue;
            }
            $type = (int)$row['i_reservation_type'];
            $map[$uid][$date][$type] = $row;
        }

        // グリッドと版数を組み立てる
        $grid     = [];
        $versions = [];
        foreach ($users as $u) {
            $uid = (int)$u['id'];
            $grid[$uid]     = [];
            $versions[$uid] = [];

            foreach ($dates as $date) {
                $grid[$uid][$date]     = [];
                $versions[$uid][$date] = [];

                foreach (array_keys($meals) as $mealType) {
                    $row = $map[$uid][$date][$mealType] ?? null;
                    if ($row === null) {
                        $grid[$uid][$date][$mealType]     = false;
                        $versions[$uid][$date][$mealType] = 1;
                    } else {
                        // 実効値の判定: i_change_flag が設定済みなら日付に関わらず優先
                        if ($row['i_change_flag'] !== null) {
                            $effective = (int)$row['i_change_flag'] === 1;
                        } else {
                            $effective = (int)($row['eat_flag'] ?? 0) === 1;
                        }
                        $grid[$uid][$date][$mealType]     = $effective;
                        $versions[$uid][$date][$mealType] = (int)($row['i_version'] ?? 1);
                    }
                }
            }
        }

        return [
            'dates'    => $dates,
            'meals'    => $meals,
            'grid'     => $grid,
            'versions' => $versions,
        ];
    }

    /**
     * 実食実績を保存する（i_change_flag を更新する）。
     *
     * 保存ロジック:
     *   - 既存レコードがある場合: i_change_flag のみを更新（楽観的ロック付き）
     *   - 既存レコードがない場合: 新規作成（eat_flag=0, i_change_flag=指定値）
     *
     * @param Table  $reservationTable TIndividualReservationInfo テーブル
     * @param int    $userId
     * @param int    $roomId
     * @param string $date             YYYY-MM-DD
     * @param int    $mealType         1=朝, 2=昼, 3=夜
     * @param bool   $checked          true=実食, false=未食
     * @param int    $expectedVersion  楽観的ロック用バージョン
     * @param string $actor            操作ユーザー名
     * @return array{ok: bool, message: string}
     */
    public function saveActualMeal(
        Table $reservationTable,
        int $userId,
        int $roomId,
        string $date,
        int $mealType,
        bool $checked,
        int $expectedVersion,
        string $actor
    ): array {
        if (!in_array($mealType, [1, 2, 3], true)) {
            return ['ok' => false, 'message' => '無効な食事タイプです。'];
        }

        $now  = DateTime::now('Asia/Tokyo');
        $flagValue = $checked ? 1 : 0;

        $entity = $reservationTable->find()
            ->enableAutoFields(false)
            ->select([
                'i_id_user', 'd_reservation_date', 'i_id_room', 'i_reservation_type',
                'eat_flag', 'i_change_flag', 'i_version',
            ])
            ->where([
                'i_id_user'          => $userId,
                'd_reservation_date' => $date,
                'i_id_room'          => $roomId,
                'i_reservation_type' => $mealType,
            ])
            ->first();

        if ($entity === null) {
            // 新規作成
            $newEntity = $reservationTable->newEmptyEntity();
            $newEntity->i_id_user          = $userId;
            $newEntity->d_reservation_date = $date;
            $newEntity->i_id_room          = $roomId;
            $newEntity->i_reservation_type = $mealType;
            $newEntity->eat_flag           = 0;
            $newEntity->i_change_flag      = $flagValue;
            $newEntity->i_version          = 1;
            $newEntity->dt_create          = $now;
            $newEntity->c_create_user      = $actor;

            if (!$reservationTable->save($newEntity)) {
                return ['ok' => false, 'message' => '保存に失敗しました。'];
            }
            return ['ok' => true, 'message' => '保存しました。', 'version' => 1];
        }

        // 楽観的ロック付き更新
        $nextVersion = $expectedVersion + 1;
        $affected = $reservationTable->updateAll(
            [
                'i_change_flag'  => $flagValue,
                'dt_update'      => $now,
                'c_update_user'  => $actor,
                'i_version'      => $nextVersion,
            ],
            [
                'i_id_user'          => $userId,
                'd_reservation_date' => $date,
                'i_id_room'          => $roomId,
                'i_reservation_type' => $mealType,
                'i_version'          => $expectedVersion,
            ]
        );

        if ($affected !== 1) {
            return ['ok' => false, 'message' => '他のユーザーによって更新されています。画面を再読み込みしてください。'];
        }

        return ['ok' => true, 'message' => '保存しました。', 'version' => $nextVersion];
    }

    /**
     * 管理者が選択可能な最も古い週の月曜日を返す（過去2ヶ月）。
     *
     * @return \DateTimeImmutable
     */
    public function getAdminOldestAllowedMonday(): \DateTimeImmutable
    {
        $today = new \DateTimeImmutable('now', new \DateTimeZone('Asia/Tokyo'));
        $oldest = $today->modify(sprintf('-%d months', self::ADMIN_LOOKBACK_MONTHS));

        return (int)$oldest->format('N') === 1
            ? $oldest
            : $oldest->modify('monday this week');
    }

    /**
     * 日付の正規化（YYYY-MM-DD 文字列に変換する）。
     */
    private function normalizeDateString(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return $value->format('Y-m-d');
        }
        if (is_object($value) && method_exists($value, 'format')) {
            try {
                return (string)$value->format('Y-m-d');
            } catch (\Throwable) {
                // fall through
            }
        }
        if (is_string($value) && $value !== '') {
            $ts = strtotime($value);
            if ($ts !== false) {
                return date('Y-m-d', $ts);
            }
        }
        return null;
    }
}
