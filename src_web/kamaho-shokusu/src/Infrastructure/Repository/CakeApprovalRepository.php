<?php
declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Entity\MealReservation;
use App\Domain\Repository\ApprovalRepositoryInterface;
use App\Domain\ValueObject\ApprovalKey;
use App\Domain\ValueObject\ApprovalStatus;
use Cake\Datasource\EntityInterface;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;

/**
 * ApprovalRepositoryInterface の CakePHP 実装（t_individual_reservation_info・t_approval_log・t_reservation_info）。
 *
 * テストで Table を差し替えられるよう、Table は使うときに取る。
 */
final class CakeApprovalRepository implements ApprovalRepositoryInterface
{
    private static function table(string $alias): Table
    {
        return TableRegistry::getTableLocator()->get($alias);
    }

    public function changeStatus(ApprovalKey $key, int $newStatus, array $allowedFrom, string $actorName, string $now): bool
    {
        $affected = self::table('TIndividualReservationInfo')->updateAll(
            ['i_approval_status' => $newStatus, 'dt_update' => $now, 'c_update_user' => $actorName],
            [
                'i_id_user'            => $key->userId,
                'd_reservation_date'   => $key->date,
                'i_id_room'            => $key->roomId,
                'i_reservation_type'   => $key->mealType,
                'i_approval_status IN' => $allowedFrom,
            ]
        );

        return $affected > 0;
    }

    public function addLog(ApprovalKey $key, int $status, int $approverId, ?string $reason, string $now): void
    {
        $logs = self::table('TApprovalLog');
        $logs->saveOrFail($logs->newEntity([
            'i_id_user'          => $key->userId,
            'd_reservation_date' => $key->date,
            'i_id_room'          => $key->roomId,
            'i_reservation_type' => $key->mealType,
            'i_approval_status'  => $status,
            'i_approver_id'      => $approverId,
            'c_reject_reason'    => $reason,
            'dt_create'          => $now,
        ]));
    }

    public function findFinalApproved(?int $roomId, ?string $dateFrom, ?string $dateTo): array
    {
        $query = self::table('TIndividualReservationInfo')->find()
            ->innerJoin(['MUserInfo' => 'm_user_info'], ['MUserInfo.i_id_user = TIndividualReservationInfo.i_id_user'])
            ->where([
                'TIndividualReservationInfo.i_approval_status' => ApprovalStatus::ADMIN,
                'MUserInfo.i_del_flag' => 0, // 削除済みの利用者は除く
            ]);
        if ($roomId !== null) {
            $query->where(['TIndividualReservationInfo.i_id_room' => $roomId]);
        }
        if ($dateFrom !== null) {
            $query->where(['TIndividualReservationInfo.d_reservation_date >=' => $dateFrom]);
        }
        if ($dateTo !== null) {
            $query->where(['TIndividualReservationInfo.d_reservation_date <=' => $dateTo]);
        }

        $reservations = [];
        foreach ($query->all() as $row) {
            if (!$row instanceof EntityInterface) {
                continue;
            }
            $eat = $row->get('eat_flag');
            $change = $row->get('i_change_flag');
            $reservations[] = new MealReservation(
                (int)$row->get('i_id_user'),
                $row->get('d_reservation_date')->format('Y-m-d'),
                (int)$row->get('i_id_room'),
                (int)$row->get('i_reservation_type'),
                $eat === null ? null : (int)$eat,
                $change === null ? null : (int)$change,
                approvalStatus: ApprovalStatus::ADMIN,
            );
        }

        return $reservations;
    }

    public function saveMealCount(int $roomId, string $date, int $mealType, int $eating, int $notEating, string $actorName, string $now): void
    {
        $counts = self::table('TReservationInfo');
        $existing = $counts->find()->where([
            'd_reservation_date' => $date,
            'i_id_room'          => $roomId,
            'c_reservation_type' => $mealType,
        ])->first();

        if ($existing instanceof EntityInterface) {
            $counts->patchEntity($existing, [
                'i_taberu_ninzuu'  => $eating,
                'i_tabenai_ninzuu' => $notEating,
                'dt_update'        => $now,
                'c_update_user'    => $actorName,
            ]);
            $counts->saveOrFail($existing);

            return;
        }

        $counts->saveOrFail($counts->newEntity([
            'i_id_room'          => $roomId,
            'd_reservation_date' => $date,
            'c_reservation_type' => $mealType,
            'i_taberu_ninzuu'    => $eating,
            'i_tabenai_ninzuu'   => $notEating,
            'dt_create'          => $now,
            'c_create_user'      => $actorName,
            'dt_update'          => $now,
            'c_update_user'      => $actorName,
        ]));
    }
}
