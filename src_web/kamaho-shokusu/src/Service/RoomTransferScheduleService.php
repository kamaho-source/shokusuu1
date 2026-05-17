<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Table\MRoomTransferScheduleTable;
use Cake\Cache\Cache;
use Cake\I18n\Date;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;

/**
 * 部屋異動予約サービス
 *
 * 予約の登録・キャンセル・適用（m_user_group切替 + 個人予約移行 + 集計更新）を担う。
 */
class RoomTransferScheduleService
{
    /**
     * 部屋異動予約を登録する。
     *
     * @param int         $userId        対象ユーザーID
     * @param int|null    $roomFromId    異動元部屋ID（NULL=新規配属）
     * @param int         $roomToId      異動先部屋ID
     * @param string      $effectiveDate 有効開始日 (Y-m-d)
     * @param string      $actor         操作者名
     * @return int 登録されたスケジュールID
     * @throws \RuntimeException バリデーション失敗時
     * @throws \Throwable DB保存失敗時
     */
    public function create(
        int $userId,
        ?int $roomFromId,
        int $roomToId,
        string $effectiveDate,
        string $actor
    ): int {
        $table = TableRegistry::getTableLocator()->get('MRoomTransferSchedule');

        $entity = $table->newEntity([
            'i_id_user'        => $userId,
            'i_id_room_from'   => $roomFromId,
            'i_id_room_to'     => $roomToId,
            'd_effective_date' => $effectiveDate,
            'i_status'         => MRoomTransferScheduleTable::STATUS_PENDING,
            'c_create_user'    => $actor,
            'dt_create'        => DateTime::now(),
        ]);

        if ($entity->hasErrors()) {
            $messages = array_map(
                fn(array $errs) => implode(', ', $errs),
                array_map('array_values', $entity->getErrors())
            );
            throw new \RuntimeException('バリデーションエラー: ' . implode(' / ', array_merge(...array_values($messages))));
        }

        $table->saveOrFail($entity);

        return (int)$entity->i_id;
    }

    /**
     * 予約中のスケジュールをキャンセルする。
     *
     * @param int    $scheduleId スケジュールID
     * @param string $actor      操作者名
     * @throws \RuntimeException 対象が存在しない・既に適用済みの場合
     * @throws \Throwable DB保存失敗時
     */
    public function cancel(int $scheduleId, string $actor): void
    {
        $table    = TableRegistry::getTableLocator()->get('MRoomTransferSchedule');
        $schedule = $table->get($scheduleId);

        if ((int)$schedule->i_status !== MRoomTransferScheduleTable::STATUS_PENDING) {
            throw new \RuntimeException('キャンセルできるのは予約中のスケジュールのみです。');
        }

        $schedule->i_status      = MRoomTransferScheduleTable::STATUS_CANCELLED;
        $schedule->c_update_user = $actor;
        $schedule->dt_update     = DateTime::now();

        $table->saveOrFail($schedule);
    }

    /**
     * 有効開始日が到来した予約中スケジュールをすべて適用する。
     *
     * 各スケジュールに対してトランザクション内で以下を実行する:
     *   1. m_user_group の部屋切替
     *   2. t_individual_reservation_info の予約移行（DELETE + INSERT）
     *   3. t_reservation_info（集計）の更新
     *   4. スケジュールを適用済みにマーク
     *   5. キャッシュ無効化
     *
     * @param string $today 基準日 (Y-m-d)
     * @param bool   $dryRun トゥルーの場合コミットしない
     * @return array{applied: int, errors: array<string>}
     */
    public function applyPending(string $today, bool $dryRun = false): array
    {
        $scheduleTable    = TableRegistry::getTableLocator()->get('MRoomTransferSchedule');
        $userGroupTable   = TableRegistry::getTableLocator()->get('MUserGroup');
        $indvResTable     = TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
        $groupResTable    = TableRegistry::getTableLocator()->get('TReservationInfo');

        $pending = $scheduleTable->find()
            ->where([
                'i_status'            => MRoomTransferScheduleTable::STATUS_PENDING,
                'd_effective_date <=' => $today,
            ])
            ->all();

        $applied = 0;
        $errors  = [];

        foreach ($pending as $schedule) {
            $conn = $scheduleTable->getConnection();
            $conn->begin();

            try {
                $userId        = (int)$schedule->i_id_user;
                $roomFromId    = $schedule->i_id_room_from !== null ? (int)$schedule->i_id_room_from : null;
                $roomToId      = (int)$schedule->i_id_room_to;
                $effectiveDate = $schedule->d_effective_date instanceof Date
                    ? $schedule->d_effective_date->format('Y-m-d')
                    : (string)$schedule->d_effective_date;
                $now           = DateTime::now();

                // Step 1: m_user_group 切替
                if ($roomFromId !== null) {
                    $userGroupTable->updateAll(
                        ['active_flag' => 1, 'dt_update' => $now, 'c_update_user' => 'batch'],
                        ['i_id_user' => $userId, 'i_id_room' => $roomFromId, 'active_flag' => 0]
                    );
                }

                $existingGroup = $userGroupTable->find()
                    ->where(['i_id_user' => $userId, 'i_id_room' => $roomToId])
                    ->first();

                if ($existingGroup) {
                    $existingGroup->active_flag   = 0;
                    $existingGroup->dt_update     = $now;
                    $existingGroup->c_update_user = 'batch';
                    $userGroupTable->saveOrFail($existingGroup);
                } else {
                    $newGroup = $userGroupTable->newEntity([
                        'i_id_user'     => $userId,
                        'i_id_room'     => $roomToId,
                        'active_flag'   => 0,
                        'dt_create'     => $now,
                        'c_create_user' => 'batch',
                    ]);
                    $userGroupTable->saveOrFail($newGroup);
                }

                // Step 2 & 3: 個人予約移行 + 集計更新
                if ($roomFromId !== null) {
                    $this->migrateIndividualReservations(
                        $indvResTable,
                        $groupResTable,
                        $userId,
                        $roomFromId,
                        $roomToId,
                        $effectiveDate,
                        $now
                    );
                }

                // Step 4: スケジュールを適用済みにマーク
                $schedule->i_status      = MRoomTransferScheduleTable::STATUS_APPLIED;
                $schedule->c_update_user = 'batch';
                $schedule->dt_update     = $now;
                $scheduleTable->saveOrFail($schedule);

                if ($dryRun) {
                    $conn->rollback();
                } else {
                    $conn->commit();
                    // Step 5: キャッシュ無効化
                    $this->invalidateCaches($userId, $roomFromId, $roomToId, $effectiveDate, $today);
                }

                $applied++;

            } catch (\Throwable $e) {
                if ($conn->inTransaction()) {
                    $conn->rollback();
                }
                $errors[] = sprintf('スケジュールID=%d: %s', (int)$schedule->i_id, $e->getMessage());
            }
        }

        return ['applied' => $applied, 'errors' => $errors];
    }

    /**
     * 個人予約レコードを旧部屋から新部屋へ移行し、集計テーブルも更新する。
     *
     * t_individual_reservation_info のPKに i_id_room が含まれるため
     * UPDATE は不可。DELETE + INSERT で対応する。
     *
     * @param \Cake\ORM\Table $indvResTable
     * @param \Cake\ORM\Table $groupResTable
     * @param int             $userId
     * @param int             $roomFromId
     * @param int             $roomToId
     * @param string          $effectiveDate
     * @param DateTime        $now
     */
    private function migrateIndividualReservations(
        object $indvResTable,
        object $groupResTable,
        int $userId,
        int $roomFromId,
        int $roomToId,
        string $effectiveDate,
        DateTime $now
    ): void {
        $oldRows = $indvResTable->find()
            ->where([
                'i_id_user'                => $userId,
                'i_id_room'                => $roomFromId,
                'd_reservation_date >='    => $effectiveDate,
            ])
            ->all();

        foreach ($oldRows as $old) {
            $dateStr  = $old->d_reservation_date instanceof Date
                ? $old->d_reservation_date->format('Y-m-d')
                : (string)$old->d_reservation_date;
            $mealType = (int)$old->i_reservation_type;
            $eatFlag  = (int)$old->eat_flag;

            // 移行先に同一PKが既にある場合はスキップ（古い行のみ削除）
            $alreadyExists = $indvResTable->exists([
                'i_id_user'          => $userId,
                'i_id_room'          => $roomToId,
                'd_reservation_date' => $dateStr,
                'i_reservation_type' => $mealType,
            ]);

            $indvResTable->deleteAll([
                'i_id_user'          => $userId,
                'i_id_room'          => $roomFromId,
                'd_reservation_date' => $dateStr,
                'i_reservation_type' => $mealType,
            ]);

            if (!$alreadyExists) {
                $newRow = $indvResTable->newEntity([
                    'i_id_user'          => $userId,
                    'i_id_room'          => $roomToId,
                    'd_reservation_date' => $dateStr,
                    'i_reservation_type' => $mealType,
                    'eat_flag'           => $eatFlag,
                    'i_change_flag'      => (int)$old->i_change_flag,
                    'i_version'          => (int)$old->i_version,
                    'c_create_user'      => (string)$old->c_create_user,
                    'dt_create'          => $old->dt_create,
                    'c_update_user'      => 'batch',
                    'dt_update'          => $now,
                ]);
                $indvResTable->saveOrFail($newRow);

                // eat_flag=1 の行だけ集計テーブルを更新
                if ($eatFlag === 1) {
                    $this->adjustGroupReservationCount($groupResTable, $dateStr, $roomFromId, $mealType, -1, $now);
                    $this->adjustGroupReservationCount($groupResTable, $dateStr, $roomToId, $mealType, +1, $now);
                }
            }
        }
    }

    /**
     * t_reservation_info の i_taberu_ninzuu を delta 分増減する。
     * レコードが存在しない場合は新規作成する（delta=+1 の時のみ）。
     *
     * @param \Cake\ORM\Table $groupResTable
     * @param string          $date
     * @param int             $roomId
     * @param int             $mealType
     * @param int             $delta  +1 or -1
     * @param DateTime        $now
     */
    private function adjustGroupReservationCount(
        object $groupResTable,
        string $date,
        int $roomId,
        int $mealType,
        int $delta,
        DateTime $now
    ): void {
        $row = $groupResTable->find()
            ->where([
                'd_reservation_date'  => $date,
                'i_id_room'           => $roomId,
                'c_reservation_type'  => $mealType,
            ])
            ->first();

        if ($row) {
            $newCount = max(0, (int)$row->i_taberu_ninzuu + $delta);
            $groupResTable->updateAll(
                ['i_taberu_ninzuu' => $newCount, 'dt_update' => $now, 'c_update_user' => 'batch'],
                [
                    'd_reservation_date' => $date,
                    'i_id_room'          => $roomId,
                    'c_reservation_type' => $mealType,
                ]
            );
            return;
        }

        if ($delta > 0) {
            $newRow = $groupResTable->newEntity([
                'd_reservation_date' => $date,
                'i_id_room'          => $roomId,
                'c_reservation_type' => $mealType,
                'i_taberu_ninzuu'    => 1,
                'i_tabenai_ninzuu'   => 0,
                'dt_create'          => $now,
                'c_create_user'      => 'batch',
            ]);
            $groupResTable->saveOrFail($newRow);
        }
    }

    /**
     * 影響する日付・部屋・ユーザーのキャッシュを無効化する。
     */
    private function invalidateCaches(
        int $userId,
        ?int $roomFromId,
        int $roomToId,
        string $effectiveDate,
        string $today
    ): void {
        // 有効開始日以降の今日分だけキャッシュ削除（全日削除は重すぎるため当日のみ対象）
        Cache::delete('meal_counts:' . $today, 'default');

        $rooms = array_filter([$roomFromId, $roomToId]);
        foreach ($rooms as $roomId) {
            Cache::delete(sprintf('users_by_room_edit:%d:%s', $roomId, $today), 'default');
        }

        Cache::delete(sprintf('today_report:%d:%s', $userId, $today), 'default');

        $current = Cache::read('reservation_version', 'default');
        $next    = (is_int($current) && $current > 0) ? $current + 1 : 2;
        Cache::write('reservation_version', $next, 'default');
    }
}
