<?php
declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Exception\ConflictException;
use App\Domain\Repository\ActualMealRepositoryInterface;
use App\Domain\ValueObject\ApprovalKey;
use App\Domain\ValueObject\ApprovalStatus;
use Cake\Datasource\EntityInterface;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;

/**
 * ActualMealRepositoryInterface の CakePHP 実装（t_individual_reservation_info）。
 *
 * テストで Table を差し替えられるよう、Table は使うときに取る。
 */
final class CakeActualMealRepository implements ActualMealRepositoryInterface
{
    private static function table(): Table
    {
        return TableRegistry::getTableLocator()->get('TIndividualReservationInfo');
    }

    public function exists(ApprovalKey $key): bool
    {
        return self::table()->exists($key->toArray());
    }

    public function findApprovalStatus(ApprovalKey $key): ?int
    {
        $row = self::table()->find()
            ->enableAutoFields(false)
            ->select(['i_approval_status'])
            ->where($key->toArray())
            ->first();
        if (!$row instanceof EntityInterface) {
            return null;
        }

        return (int)($row->get('i_approval_status') ?? ApprovalStatus::PENDING);
    }

    public function create(ApprovalKey $key, int $flag, string $actorName, string $now): bool
    {
        $table = self::table();
        try {
            $entity = $table->newEmptyEntity();
            $entity->set([
                'i_id_user'          => $key->userId,
                'd_reservation_date' => $key->date,
                'i_id_room'          => $key->roomId,
                'i_reservation_type' => $key->mealType,
                'eat_flag'           => $flag,
                'i_change_flag'      => $flag,
                'i_version'          => 1,
                'dt_create'          => $now,
                'c_create_user'      => $actorName,
                'i_approval_status'  => ApprovalStatus::PENDING,
            ], ['guard' => false]);

            return (bool)$table->save($entity, ['checkDuplicateRoom' => false]);
        } catch (\Exception) {
            // 同時に作られて一意制約に反したなど。既に行がある可能性があるので、呼び出し側に再読み込みを促させる
            throw new ConflictException();
        }
    }

    public function updateFlag(ApprovalKey $key, int $flag, int $expectedVersion, string $actorName, string $now): int
    {
        return self::table()->updateAll(
            [
                'eat_flag'      => $flag,
                'i_change_flag' => $flag,
                'dt_update'     => $now,
                'c_update_user' => $actorName,
                'i_version'     => $expectedVersion + 1,
            ],
            [...$key->toArray(), 'i_version' => $expectedVersion]
        );
    }

    public function changeApprovalStatus(ApprovalKey $key, int $newStatus, array $allowedFrom, string $actorName, string $now): int
    {
        return self::table()->updateAll(
            ['i_approval_status' => $newStatus, 'dt_update' => $now, 'c_update_user' => $actorName],
            [...$key->toArray(), 'i_approval_status IN' => $allowedFrom]
        );
    }
}
