<?php
declare(strict_types=1);

namespace App\Service;

use App\Domain\ValueObject\MealType;
use App\Exception\DuplicateMealReservationException;
use App\Infrastructure\Cache\ReservationCache;
use App\Model\Table\TIndividualReservationInfoTable;
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
    private const EAT_REPORT_MEAL_TYPES = [MealType::Breakfast->value, MealType::Lunch->value, MealType::Dinner->value];

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
                // 版を上げて、同時に開いていた画面からの古い保存を競合として止める（updateRowWithVersion と同じ）
                'i_version'     => $reservationTable->updateQuery()->expr('i_version + 1'),
            ],
            [
                'i_id_user'             => $userId,
                'd_reservation_date'    => $today,
                'i_reservation_type IN' => MealType::values(),
                // 承認済みの行は変えない（他の書き込み経路と同じく、承認後の内容を守る）
                'OR' => [
                    'i_approval_status IS'     => null,
                    'i_approval_status NOT IN' => TIndividualReservationInfoTable::APPROVED_STATUSES,
                ],
            ]
        );

        // 予約行が1つも無い食事は、従来どおり代表部屋に「食べない」の行を残す
        foreach (MealType::values() as $mealType) {
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

        // 承認済みの食事は変えていないので、黙って「報告しました」にしない
        $approvedRows = $reservationTable->find()
            ->enableAutoFields(false)
            ->select(['d_reservation_date', 'eat_flag', 'i_change_flag'])
            ->where([
                'i_id_user'            => $userId,
                'd_reservation_date'   => $today,
                'i_approval_status IN' => TIndividualReservationInfoTable::APPROVED_STATUSES,
            ])
            ->all();
        $approvedLeft = false;
        foreach ($approvedRows as $r) {
            $approvedLeft = $approvedLeft || TIndividualReservationInfoTable::isEffectiveRow($r);
        }
        if ($approvedLeft) {
            return ['ok' => true, 'message' => '食べないで報告しました。承認済みの食事は変更されていません。'];
        }

        return ['ok' => true, 'message' => '食べないで報告しました。'];
    }

    /**
     * 「食べる」で報告する（朝・昼・夕を代表部屋で eat_flag=1, i_change_flag=1 に設定）。
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

        // 代表部屋の既存行を「食べる」にしようとした食事（承認済みなら更新されずに残る）
        $updatedMealTypes = [];
        try {
            $reservationTable->getConnection()->transactional(function () use ($reservationTable, $userId, $userName, $roomId, $today, &$updatedMealTypes): void {
                // 「食べる」は朝・昼・夕。弁当は昼食と同時に有効にできないため付けない。
                // 弁当をすでに予約している日は、昼食も付けない（弁当のまま）
                foreach (self::EAT_REPORT_MEAL_TYPES as $mealType) {
                    $counterpart = MealType::counterpartValue($mealType);
                    if ($counterpart !== null && $this->hasEffectiveMeal($reservationTable, $userId, $today, $counterpart)) {
                        continue;
                    }
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
                        $updatedMealTypes[] = $mealType;
                        $reservationTable->updateAll(
                            [
                                'eat_flag'      => 1,
                                'i_change_flag' => 1,
                                'c_update_user' => $userName,
                                'dt_update'     => DateTime::now('Asia/Tokyo'),
                                // 版を上げて、同時に開いていた画面からの古い保存を競合として止める（updateRowWithVersion と同じ）
                                'i_version'     => $reservationTable->updateQuery()->expr('i_version + 1'),
                            ],
                            [
                                'i_id_user'          => $userId,
                                'd_reservation_date' => $today,
                                'i_reservation_type' => $mealType,
                                'i_id_room'          => $roomId,
                                'OR' => [
                                    'i_approval_status IS'     => null,
                                    'i_approval_status NOT IN' => TIndividualReservationInfoTable::APPROVED_STATUSES,
                                ],
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
        ReservationCache::markTodayReported($userId, $today);

        // 承認済みのため変えられなかった食事があれば、「食べない」の報告と同じく黙って「報告しました」にしない
        if ($updatedMealTypes === []) {
            return ['ok' => true, 'message' => '食べるで報告しました。'];
        }
        $approvedRows = $reservationTable->find()
            ->enableAutoFields(false)
            ->select(['d_reservation_date', 'eat_flag', 'i_change_flag'])
            ->where([
                'i_id_user'             => $userId,
                'd_reservation_date'    => $today,
                'i_id_room'             => $roomId,
                'i_reservation_type IN' => $updatedMealTypes,
                'i_approval_status IN'  => TIndividualReservationInfoTable::APPROVED_STATUSES,
            ])
            ->all();
        foreach ($approvedRows as $r) {
            if (!TIndividualReservationInfoTable::isEffectiveRow($r)) {
                return ['ok' => true, 'message' => '食べるで報告しました。承認済みの食事は変更されていません。'];
            }
        }

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

    private function hasEffectiveMeal(Table $reservationTable, int $userId, string $date, int $mealType): bool
    {
        $rows = $reservationTable->find()
            ->enableAutoFields(false)
            ->select(['d_reservation_date', 'eat_flag', 'i_change_flag'])
            ->where(['i_id_user' => $userId, 'd_reservation_date' => $date, 'i_reservation_type' => $mealType])
            ->all();
        foreach ($rows as $r) {
            if (TIndividualReservationInfoTable::isEffectiveRow($r)) {
                return true;
            }
        }

        return false;
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
        ReservationCache::forgetDate($today, [$userId]);
    }
}
