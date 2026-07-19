<?php
declare(strict_types=1);

namespace App\Service;

use App\Application\Tenant\TenantContextHolder;
use Cake\Cache\Cache;
use Cake\I18n\Date;
use Cake\I18n\DateTime;
use Cake\ORM\Table;

/**
 * 食事報告サービス
 *
 * 「食べない」「食べる」の当日報告処理を担う。
 * ORM の updateAll / 新規エンティティ保存・キャッシュ無効化を集約する。
 */
class MealReportingService
{
    private const MEAL_TYPES = [1, 2, 3, 4];

    /**
     * 「食べない」で報告する（全食種を eat_flag=0, i_change_flag=0 に設定）。
     *
     * @param Table  $reservationTable TIndividualReservationInfo テーブル
     * @param Table  $userGroupTable   MUserGroup テーブル
     * @param int    $userId           ログインユーザーID
     * @param string $userName         ログインユーザー名
     * @return array{ok: bool, message: string}
     */
    public function reportNoMeal(Table $reservationTable, Table $userGroupTable, int $userId, string $userName): array
    {
        $roomId = $this->getPrimaryRoomId($userGroupTable, $userId);
        if ($roomId === null) {
            return ['ok' => false, 'message' => '所属部屋が設定されていません。管理者にお問い合わせください。'];
        }

        $today = Date::today('Asia/Tokyo')->format('Y-m-d');

        foreach (self::MEAL_TYPES as $mealType) {
            $affected = $reservationTable->updateAll(
                [
                    'eat_flag'      => 0,
                    'i_change_flag' => 0,
                    'c_update_user' => $userName,
                    'dt_update'     => DateTime::now('Asia/Tokyo'),
                ],
                [
                    'i_id_user'          => $userId,
                    'd_reservation_date' => $today,
                    'i_reservation_type' => $mealType,
                    'i_id_room'          => $roomId,
                ]
            );

            if ($affected === 0) {
                $noMealCtx = TenantContextHolder::get();
                $entity = $reservationTable->newEmptyEntity();
                $entity->tenant_id          = $noMealCtx !== null ? $noMealCtx->tenantId() : 1;
                $entity->facility_id        = $noMealCtx !== null ? $noMealCtx->tenantId() : 1;
                $entity->i_id_user          = $userId;
                $entity->d_reservation_date = $today;
                $entity->i_reservation_type = $mealType;
                $entity->i_id_room          = $roomId;
                $entity->eat_flag           = 0;
                $entity->i_change_flag      = 0;
                $entity->c_create_user      = $userName;
                $entity->dt_create          = DateTime::now('Asia/Tokyo');

                if (!$reservationTable->save($entity)) {
                    return ['ok' => false, 'message' => '報告の保存に失敗しました。'];
                }
            }
        }

        $this->invalidateCaches($userId, $roomId, $today);

        return ['ok' => true, 'message' => '食べないで報告しました。'];
    }

    /**
     * 「食べる」で報告する（全食種を eat_flag=1, i_change_flag=1 に設定）。
     *
     * @param Table  $reservationTable TIndividualReservationInfo テーブル
     * @param Table  $userGroupTable   MUserGroup テーブル
     * @param int    $userId           ログインユーザーID
     * @param string $userName         ログインユーザー名
     * @return array{ok: bool, message: string}
     */
    public function reportEat(Table $reservationTable, Table $userGroupTable, int $userId, string $userName): array
    {
        $roomId = $this->getPrimaryRoomId($userGroupTable, $userId);
        if ($roomId === null) {
            return ['ok' => false, 'message' => '所属部屋が設定されていません。管理者にお問い合わせください。'];
        }

        $today = Date::today('Asia/Tokyo')->format('Y-m-d');

        foreach (self::MEAL_TYPES as $mealType) {
            $affected = $reservationTable->updateAll(
                [
                    'eat_flag'      => 1,
                    'i_change_flag' => 1,
                    'c_update_user' => $userName,
                    'dt_update'     => DateTime::now('Asia/Tokyo'),
                ],
                [
                    'i_id_user'          => $userId,
                    'd_reservation_date' => $today,
                    'i_reservation_type' => $mealType,
                    'i_id_room'          => $roomId,
                ]
            );

            if ($affected === 0) {
                $eatCtx = TenantContextHolder::get();
                $entity = $reservationTable->newEmptyEntity();
                $entity->tenant_id          = $eatCtx !== null ? $eatCtx->tenantId() : 1;
                $entity->facility_id        = $eatCtx !== null ? $eatCtx->tenantId() : 1;
                $entity->i_id_user          = $userId;
                $entity->d_reservation_date = $today;
                $entity->i_reservation_type = $mealType;
                $entity->i_id_room          = $roomId;
                $entity->eat_flag           = 1;
                $entity->i_change_flag      = 1;
                $entity->c_create_user      = $userName;
                $entity->dt_create          = DateTime::now('Asia/Tokyo');

                if (!$reservationTable->save($entity)) {
                    return ['ok' => false, 'message' => '報告の保存に失敗しました。'];
                }
            }
        }

        $this->invalidateCaches($userId, $roomId, $today);
        Cache::write(sprintf('today_report:%d:%s', $userId, $today), 1, 'default');

        return ['ok' => true, 'message' => '食べるで報告しました。'];
    }

    private function getPrimaryRoomId(Table $userGroupTable, int $userId): ?int
    {
        $row = $userGroupTable->find()
            ->enableAutoFields(false)
            ->select(['i_id_room'])
            ->where(['i_id_user' => $userId, 'active_flag' => 0])
            ->orderAsc('i_id_room')
            ->first();

        return $row ? (int)$row->i_id_room : null;
    }

    private function invalidateCaches(int $userId, int $roomId, string $today): void
    {
        Cache::delete(sprintf('today_report:%d:%s', $userId, $today), 'default');
        Cache::delete('meal_counts:' . $today, 'default');
        Cache::delete(sprintf('users_by_room_edit:%d:%s', $roomId, $today), 'default');
    }
}
