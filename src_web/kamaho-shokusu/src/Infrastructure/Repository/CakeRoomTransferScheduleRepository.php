<?php
declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Entity\RoomTransferSchedule;
use App\Domain\Repository\RoomTransferScheduleRepositoryInterface;
use Cake\Datasource\EntityInterface;
use Cake\I18n\Date;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;

/**
 * RoomTransferScheduleRepositoryInterface の CakePHP 実装（m_room_transfer_schedule）。
 */
final class CakeRoomTransferScheduleRepository implements RoomTransferScheduleRepositoryInterface
{
    private readonly Table $schedules;

    public function __construct(?Table $schedules = null)
    {
        $this->schedules = $schedules ?? TableRegistry::getTableLocator()->get('MRoomTransferSchedule');
    }

    public function create(array $values, string $createdBy, string $at): array
    {
        $entity = $this->schedules->newEntity($values + [
            'i_status'      => RoomTransferSchedule::STATUS_PENDING,
            'c_create_user' => $createdBy,
            'dt_create'     => $at,
        ]);
        if ($entity->hasErrors()) {
            return $entity->getErrors();
        }
        $this->schedules->saveOrFail($entity);

        return [];
    }

    public function find(int $scheduleId): ?RoomTransferSchedule
    {
        $row = $this->schedules->find()->where(['i_id' => $scheduleId])->first();

        return $row === null ? null : self::toSchedule($row);
    }

    public function changeStatus(int $scheduleId, int $status, string $updatedBy, string $at, array $onlyFrom = []): bool
    {
        $where = ['i_id' => $scheduleId];
        if ($onlyFrom !== []) {
            $where['i_status IN'] = $onlyFrom;
        }

        return $this->schedules->updateAll(
            ['i_status' => $status, 'c_fail_reason' => null, 'c_update_user' => $updatedBy, 'dt_update' => $at],
            $where
        ) > 0;
    }

    public function markFailed(int $scheduleId, string $reason, string $at): bool
    {
        return $this->schedules->updateAll(
            [
                'i_status'      => RoomTransferSchedule::STATUS_FAILED,
                'c_fail_reason' => mb_substr($reason, 0, 255),
                'c_update_user' => 'batch',
                'dt_update'     => $at,
            ],
            ['i_id' => $scheduleId, 'i_status' => RoomTransferSchedule::STATUS_PENDING]
        ) > 0;
    }

    public function reopen(int $scheduleId, string $effectiveDate, string $updatedBy, string $at): bool
    {
        return $this->schedules->updateAll(
            [
                'i_status'         => RoomTransferSchedule::STATUS_PENDING,
                'd_effective_date' => $effectiveDate,
                'c_fail_reason'    => null,
                'c_update_user'    => $updatedBy,
                'dt_update'        => $at,
            ],
            ['i_id' => $scheduleId, 'i_status' => RoomTransferSchedule::STATUS_FAILED]
        ) > 0;
    }

    public function pendingUntil(string $date): array
    {
        $rows = $this->schedules->find()
            ->where(['i_status' => RoomTransferSchedule::STATUS_PENDING, 'd_effective_date <=' => $date])
            ->all();

        $schedules = [];
        foreach ($rows as $row) {
            $schedules[] = self::toSchedule($row);
        }

        return $schedules;
    }

    private static function toSchedule(EntityInterface $row): RoomTransferSchedule
    {
        $date = $row->get('d_effective_date');

        return new RoomTransferSchedule(
            (int)$row->get('i_id'),
            (int)$row->get('i_id_user'),
            $row->get('i_id_room_from') === null ? null : (int)$row->get('i_id_room_from'),
            (int)$row->get('i_id_room_to'),
            $date instanceof Date ? $date->format('Y-m-d') : (string)$date,
            (int)$row->get('i_status'),
            $row->get('c_fail_reason') === null ? null : (string)$row->get('c_fail_reason'),
        );
    }
}
