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

    /** @var array<int, int> 予約ID => 今の状態（バッチの途中で、ほかの操作が状態を変えたことにする） */
    public array $currentStatus = [];

    public function changeStatus(int $scheduleId, int $status, string $updatedBy, string $at, array $onlyFrom = []): bool
    {
        if (!$this->isIn($scheduleId, $onlyFrom)) {
            return false;
        }
        $this->statusChanges[] = [$scheduleId, $status, $updatedBy];

        return true;
    }

    /**
     * @param list<int> $statuses 空なら状態を問わない
     */
    private function isIn(int $scheduleId, array $statuses): bool
    {
        $current = $this->currentStatus[$scheduleId] ?? ($this->schedules[$scheduleId]->status ?? null);

        return $statuses === [] || in_array($current, $statuses, true);
    }

    /** @var list<array{int, string}> markFailed の呼び出し [予約ID, 理由] */
    public array $failures = [];

    public function markFailed(int $scheduleId, string $reason, string $at): bool
    {
        if (!$this->isIn($scheduleId, [RoomTransferSchedule::STATUS_PENDING])) {
            return false;
        }
        $this->failures[] = [$scheduleId, $reason];

        return true;
    }

    public function pendingUntil(string $date): array
    {
        return array_values(array_filter($this->schedules, fn(RoomTransferSchedule $s) => $s->isPending() && $s->effectiveDate <= $date));
    }
}
