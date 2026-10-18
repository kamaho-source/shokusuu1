<?php
declare(strict_types=1);

namespace App\Service;

use App\Exception\DuplicateMealReservationException;
use App\Model\Table\TIndividualReservationInfoTable;
use Cake\Cache\Cache;
use Cake\I18n\Date;
use Cake\I18n\DateTime;
use Cake\ORM\Exception\PersistenceFailedException;
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

        // 食事ごとに部屋を選べるため、代表部屋だけでなく、その日のすべての部屋の予約を取り消す
        $reservationTable->updateAll(
            [
                'eat_flag'      => 0,
                'i_change_flag' => 0,
                'c_update_user' => $userName,
                'dt_update'     => DateTime::now('Asia/Tokyo'),
            ],
            [
                'i_id_user'             => $userId,
                'd_reservation_date'    => $today,
                'i_reservation_type IN' => self::MEAL_TYPES,
            ]
        );

        // 予約行が1つも無い食事は、従来どおり代表部屋に「食べない」の行を残す
        foreach (self::MEAL_TYPES as $mealType) {
            $exists = $reservationTable->exists([
                'i_id_user'          => $userId,
                'd_reservation_date' => $today,
                'i_reservation_type' => $mealType,
            ]);
            if ($exists) {
                continue;
            }
            $entity = $reservationTable->newEmptyEntity();
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

        foreach ($this->roomIdsOnDate($reservationTable, $userId, $today, $roomId) as $rid) {
            $this->invalidateCaches($userId, $rid, $today);
        }

        return ['ok' => true, 'message' => '食べないで報告しました。'];
    }

    /**
     * 「食べる」で報告する（全食種を代表部屋で eat_flag=1, i_change_flag=1 に設定）。
     *
     * 別の部屋ですでに有効な食事は、その部屋の予約のまま残す（代表部屋にも付けると二重に数えられるため）。
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

        try {
            $reservationTable->getConnection()->transactional(function () use ($reservationTable, $userId, $userName, $roomId, $today): void {
                foreach (self::MEAL_TYPES as $mealType) {
                    $rows = $reservationTable->find()
                        ->enableAutoFields(false)
                        ->select(['i_id_room', 'd_reservation_date', 'eat_flag', 'i_change_flag'])
                        ->where(['i_id_user' => $userId, 'd_reservation_date' => $today, 'i_reservation_type' => $mealType])
                        ->all();
                    $hasPrimaryRow = false;
                    $effectiveElsewhere = false;
                    foreach ($rows as $r) {
                        $hasPrimaryRow = $hasPrimaryRow || (int)$r->i_id_room === $roomId;
                        $effectiveElsewhere = $effectiveElsewhere
                            || ((int)$r->i_id_room !== $roomId && TIndividualReservationInfoTable::isEffectiveRow($r));
                    }
                    if ($effectiveElsewhere) {
                        continue;
                    }

                    if ($hasPrimaryRow) {
                        $reservationTable->updateAll(
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
                        continue;
                    }

                    $entity = $reservationTable->newEmptyEntity();
                    $entity->i_id_user          = $userId;
                    $entity->d_reservation_date = $today;
                    $entity->i_reservation_type = $mealType;
                    $entity->i_id_room          = $roomId;
                    $entity->eat_flag           = 1;
                    $entity->i_change_flag      = 1;
                    $entity->c_create_user      = $userName;
                    $entity->dt_create          = DateTime::now('Asia/Tokyo');
                    $reservationTable->saveOrFail($entity);
                }
            });
        } catch (DuplicateMealReservationException $e) {
            return ['ok' => false, 'message' => $e->getMessage()];
        } catch (PersistenceFailedException $e) {
            // 昼食と弁当は同時に有効にできない（テーブルの検証ルール）など。途中までの保存は取り消される
            return ['ok' => false, 'message' => '報告の保存に失敗しました。'];
        }

        $this->invalidateCaches($userId, $roomId, $today);
        Cache::write(sprintf('today_report:%d:%s', $userId, $today), 1, 'default');

        return ['ok' => true, 'message' => '食べるで報告しました。'];
    }

    private function getPrimaryRoomId(Table $userGroupTable, int $userId): ?int
    {
        // 削除済みの部屋は選ばない
        $row = $userGroupTable->find()
            ->enableAutoFields(false)
            ->select(['i_id_room' => 'MUserGroup.i_id_room'])
            ->innerJoin(
                ['MRoomInfo' => 'm_room_info'],
                ['MRoomInfo.i_id_room = MUserGroup.i_id_room']
            )
            ->where(['MUserGroup.i_id_user' => $userId, 'MUserGroup.active_flag' => 0, 'MRoomInfo.i_del_flg' => 0])
            ->orderAsc('MUserGroup.i_id_room')
            ->first();

        return $row ? (int)$row->i_id_room : null;
    }

    /**
     * その日に予約行がある部屋（無ければ代表部屋）を返す。キャッシュ無効化用。
     *
     * @return list<int>
     */
    private function roomIdsOnDate(Table $reservationTable, int $userId, string $date, int $primaryRoomId): array
    {
        $ids = $reservationTable->find()
            ->enableAutoFields(false)
            ->select(['i_id_room'])
            ->distinct(['i_id_room'])
            ->where(['i_id_user' => $userId, 'd_reservation_date' => $date])
            ->all()
            ->extract('i_id_room')
            ->map(fn($v): int => (int)$v)
            ->toList();

        return array_values(array_unique(array_merge([$primaryRoomId], $ids)));
    }

    private function invalidateCaches(int $userId, int $roomId, string $today): void
    {
        Cache::delete(sprintf('today_report:%d:%s', $userId, $today), 'default');
        ReservationReportService::invalidateMealCountsCache($today);
        Cache::delete(sprintf('users_by_room_edit:%d:%s', $roomId, $today), 'default');
    }
}
