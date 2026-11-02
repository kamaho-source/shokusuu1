<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\Fake;

use App\Domain\Entity\RoomTransferSchedule;
use App\Domain\Repository\RoomTransferScheduleRepositoryInterface;

/**
 * RoomTransferScheduleRepositoryInterface の Fake（メモリ上）。
 */
final class FakeRoomTransferScheduleRepository implements RoomTransferScheduleRepositoryInterface
{
    /** @var array<int, RoomTransferSchedule> */
    public array $schedules = [];

    /** @var list<array{int, int, string}> changeStatus の呼び出し [予約ID, 状態, 更新者] */
    public array $statusChanges = [];

    public function create(array $values, string $createdBy, string $at): array
    {
        return [];
    }

    public function find(int $scheduleId): ?RoomTransferSchedule
    {
        return $this->schedules[$scheduleId] ?? null;
    }

    public function changeStatus(int $scheduleId, int $status, string $updatedBy, string $at): void
    {
        $this->statusChanges[] = [$scheduleId, $status, $updatedBy];
    }

    public function pendingUntil(string $date): array
    {
        return array_values(array_filter($this->schedules, fn(RoomTransferSchedule $s) => $s->isPending() && $s->effectiveDate <= $date));
    }
}
