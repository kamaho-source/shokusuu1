<?php
declare(strict_types=1);

namespace App\Service;

use App\Model\Table\TReservationApprovalTable;
use Cake\I18n\Date;
use Cake\I18n\DateTime;
use Cake\ORM\Table;

class ReservationApprovalService
{
    public function upsertPending(
        Table $approvalTable,
        int $userId,
        string $reservationDate,
        int $roomId,
        int $mealType,
        int $requestedFlag,
        string $actor
    ): void {
        $this->upsertApproval(
            $approvalTable,
            $userId,
            $reservationDate,
            $roomId,
            $mealType,
            $requestedFlag,
            TReservationApprovalTable::STATUS_PENDING,
            null,
            null,
            null,
            $actor
        );
    }

    public function upsertApproved(
        Table $approvalTable,
        int $userId,
        string $reservationDate,
        int $roomId,
        int $mealType,
        int $requestedFlag,
        int $reviewerUserId,
        string $reviewerName
    ): void {
        $this->upsertApproval(
            $approvalTable,
            $userId,
            $reservationDate,
            $roomId,
            $mealType,
            $requestedFlag,
            TReservationApprovalTable::STATUS_APPROVED,
            $reviewerUserId,
            DateTime::now(),
            null,
            $reviewerName
        );
    }

    public function reject(
        Table $approvalTable,
        int $userId,
        string $reservationDate,
        int $roomId,
        int $mealType,
        int $reviewerUserId,
        string $reviewerName,
        ?string $reason
    ): bool {
        $existing = $this->findByKey($approvalTable, $userId, $reservationDate, $roomId, $mealType);
        if (!$existing) {
            return false;
        }

        $existing->i_status = TReservationApprovalTable::STATUS_REJECTED;
        $existing->i_reviewer_user = $reviewerUserId;
        $existing->dt_reviewed = DateTime::now();
        $existing->c_reason = $reason;
        $existing->dt_update = DateTime::now();
        $existing->c_update_user = $reviewerName;

        $approvalTable->saveOrFail($existing);
        return true;
    }

    public function approve(
        Table $approvalTable,
        int $userId,
        string $reservationDate,
        int $roomId,
        int $mealType,
        int $reviewerUserId,
        string $reviewerName,
        ?string $reason
    ): bool {
        $existing = $this->findByKey($approvalTable, $userId, $reservationDate, $roomId, $mealType);
        if (!$existing) {
            return false;
        }

        $existing->i_status = TReservationApprovalTable::STATUS_APPROVED;
        $existing->i_reviewer_user = $reviewerUserId;
        $existing->dt_reviewed = DateTime::now();
        $existing->c_reason = $reason;
        $existing->dt_update = DateTime::now();
        $existing->c_update_user = $reviewerName;

        $approvalTable->saveOrFail($existing);
        return true;
    }

    public function pendingList(Table $approvalTable, ?string $from = null, ?string $to = null): array
    {
        $conditions = ['TReservationApproval.i_status' => TReservationApprovalTable::STATUS_PENDING];
        if ($from !== null && $from !== '') {
            $conditions['TReservationApproval.d_reservation_date >='] = new Date($from);
        }
        if ($to !== null && $to !== '') {
            $conditions['TReservationApproval.d_reservation_date <='] = new Date($to);
        }

        return $approvalTable->find()
            ->enableAutoFields(false)
            ->select([
                'i_id_user' => 'TReservationApproval.i_id_user',
                'd_reservation_date' => 'TReservationApproval.d_reservation_date',
                'i_id_room' => 'TReservationApproval.i_id_room',
                'i_reservation_type' => 'TReservationApproval.i_reservation_type',
                'i_requested_flag' => 'TReservationApproval.i_requested_flag',
                'dt_create' => 'TReservationApproval.dt_create',
                'request_user_name' => 'MUserInfo.c_user_name',
                'room_name' => 'MRoomInfo.c_room_name',
            ])
            ->leftJoin(['MUserInfo' => 'm_user_info'], ['MUserInfo.i_id_user = TReservationApproval.i_id_user'])
            ->leftJoin(['MRoomInfo' => 'm_room_info'], ['MRoomInfo.i_id_room = TReservationApproval.i_id_room'])
            ->where($conditions)
            ->orderBy([
                'TReservationApproval.d_reservation_date' => 'ASC',
                'TReservationApproval.i_id_room' => 'ASC',
                'TReservationApproval.i_id_user' => 'ASC',
                'TReservationApproval.i_reservation_type' => 'ASC',
            ])
            ->enableHydration(false)
            ->toArray();
    }

    private function upsertApproval(
        Table $approvalTable,
        int $userId,
        string $reservationDate,
        int $roomId,
        int $mealType,
        int $requestedFlag,
        int $status,
        ?int $reviewerUserId,
        ?DateTime $reviewedAt,
        ?string $reason,
        string $actor
    ): void {
        $existing = $this->findByKey($approvalTable, $userId, $reservationDate, $roomId, $mealType);
        if (!$existing) {
            $entity = $approvalTable->newEntity([
                'i_id_user' => $userId,
                'd_reservation_date' => $reservationDate,
                'i_id_room' => $roomId,
                'i_reservation_type' => $mealType,
                'i_requested_flag' => $requestedFlag,
                'i_status' => $status,
                'i_reviewer_user' => $reviewerUserId,
                'dt_reviewed' => $reviewedAt,
                'c_reason' => $reason,
                'dt_create' => DateTime::now(),
                'c_create_user' => $actor,
                'dt_update' => DateTime::now(),
                'c_update_user' => $actor,
            ]);
            $approvalTable->saveOrFail($entity);
            return;
        }

        $existing->i_requested_flag = $requestedFlag;
        $existing->i_status = $status;
        $existing->i_reviewer_user = $reviewerUserId;
        $existing->dt_reviewed = $reviewedAt;
        $existing->c_reason = $reason;
        $existing->dt_update = DateTime::now();
        $existing->c_update_user = $actor;
        $approvalTable->saveOrFail($existing);
    }

    private function findByKey(
        Table $approvalTable,
        int $userId,
        string $reservationDate,
        int $roomId,
        int $mealType
    ) {
        return $approvalTable->find()
            ->where([
                'i_id_user' => $userId,
                'd_reservation_date' => $reservationDate,
                'i_id_room' => $roomId,
                'i_reservation_type' => $mealType,
            ])
            ->first();
    }
}
