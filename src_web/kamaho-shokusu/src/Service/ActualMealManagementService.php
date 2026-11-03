<?php
declare(strict_types=1);

namespace App\Service;

use Cake\I18n\DateTime;
use Cake\ORM\Table;

/**
 * ActualMealManagementService
 *
 * 実食確認管理（大人限定）機能のビジネスロジックを担当するサービスクラス。
 *
 * 責務: 実食実績の保存（i_change_flag の更新）と承認申請。読み取りは ActualMealQueryInterface。
 */
class ActualMealManagementService
{
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
        string $actor,
        string $actorLoginId = ''
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
                'eat_flag', 'i_change_flag', 'i_version', 'i_approval_status',
            ])
            ->where([
                'i_id_user'          => $userId,
                'd_reservation_date' => $date,
                'i_id_room'          => $roomId,
                'i_reservation_type' => $mealType,
            ])
            ->first();

        if ($entity !== null) {
            $currentStatus = (int)($entity->i_approval_status ?? 0);
            if ($currentStatus === 1 || $currentStatus === 2) {
                return ['ok' => false, 'message' => '承認済みのデータは変更できません。'];
            }
        }

        if ($entity === null) {
            // 新規作成
            try {
                $newEntity = $reservationTable->newEmptyEntity();
                $newEntity->i_id_user          = $userId;
                $newEntity->d_reservation_date = $date;
                $newEntity->i_id_room          = $roomId;
                $newEntity->i_reservation_type = $mealType;
                $newEntity->eat_flag           = $flagValue;
                $newEntity->i_change_flag      = $flagValue;
                $newEntity->i_version          = 1;
                $newEntity->dt_create          = $now;
                $newEntity->c_create_user      = $actor;
                $newEntity->i_approval_status  = 0;

                if (!$reservationTable->save($newEntity, ['checkDuplicateRoom' => false])) {
                    return ['ok' => false, 'message' => '保存に失敗しました。'];
                }
            } catch (\Exception $e) {
                // 同時実行による一意制約違反などの場合は、既にデータが存在する可能性があるため再取得を促す
                return ['ok' => false, 'message' => 'データの状態が変わりました。画面を再読み込みしてください。'];
            }

            // 更新と同じ形で残す。新規作成であることは detail.created で区別する
            AuditLogService::record(
                'actual_meal',
                'actual_meal_save',
                $actor,
                $userId,
                't_individual_reservation_info',
                "{$userId}:{$date}:{$roomId}:{$mealType}",
                ['checked' => $checked, 'meal_type' => $mealType, 'created' => true],
                null,
                1,
                $actorLoginId
            );

            return ['ok' => true, 'message' => '保存しました。', 'version' => 1];
        }

        // 楽観的ロック付き更新
        $nextVersion = $expectedVersion + 1;
        $affected = $reservationTable->updateAll(
            [
                'eat_flag'       => $flagValue,
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

        AuditLogService::record(
            'actual_meal',
            'actual_meal_save',
            $actor,
            $userId,
            't_individual_reservation_info',
            "{$userId}:{$date}:{$roomId}:{$mealType}",
            ['checked' => $checked, 'meal_type' => $mealType],
            null,
            1,
            $actorLoginId
        );

        return ['ok' => true, 'message' => '保存しました。', 'version' => $nextVersion];
    }

    /**
     * 実食確認の承認申請をまとめて行う（i_approval_status を 0 にリセット）。
     *
     * @param Table  $reservationTable TIndividualReservationInfo テーブル
     * @param array  $keys             申請対象キーの配列（user_id, room_id, date, meal_type を含む）
     * @param string $actor            操作者ユーザー名
     * @return int 更新件数合計
     */
    public function requestApproval(Table $reservationTable, array $keys, string $actor, string $actorLoginId = ''): int
    {
        $now          = \Cake\I18n\DateTime::now('Asia/Tokyo');
        $affectedTotal = 0;

        foreach ($keys as $key) {
            $affectedTotal += $reservationTable->updateAll(
                [
                    'i_approval_status' => 0,
                    'dt_update'         => $now,
                    'c_update_user'     => $actor,
                ],
                [
                    'i_id_user'          => (int)$key['user_id'],
                    'i_id_room'          => (int)$key['room_id'],
                    'd_reservation_date' => (string)$key['date'],
                    'i_reservation_type' => (int)$key['meal_type'],
                    'i_approval_status IN' => [0, 3], // 未承認または差し戻しのみ申請可能
                ]
            );
        }

        if ($affectedTotal > 0) {
            AuditLogService::record(
                'actual_meal',
                'actual_meal_approval_request',
                $actor,
                0,
                't_individual_reservation_info',
                null,
                ['count' => $affectedTotal],
                null,
                1,
                $actorLoginId
            );
        }

        return $affectedTotal;
    }
}
