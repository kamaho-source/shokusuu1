<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\UpdateRoom;

use App\Application\Port\AuditActor;
use App\Application\UseCase\UpdateRoom\UpdateRoomInput;
use App\Application\UseCase\UpdateRoom\UpdateRoomUseCase;
use App\Domain\Entity\Room;
use App\Domain\Entity\RoomSaveResult;
use App\Domain\Exception\NotFoundException;
use App\Domain\Repository\RoomRepositoryInterface;
use App\Test\TestCase\Application\Fake\SpyAuditLog;
use App\Test\TestCase\Application\Fake\SpyTransaction;
use Cake\Chronos\ClockFactory;
use PHPUnit\Framework\TestCase;

/**
 * UpdateRoomUseCase の、DB の失敗と部屋が無い場合のテスト（Repository は無名クラス）。
 */
class UpdateRoomUseCaseTest extends TestCase
{
    private function repository(?\Throwable $setThrows): RoomRepositoryInterface
    {
        return new class ($setThrows) implements RoomRepositoryInterface {
            public function __construct(private readonly ?\Throwable $setThrows) {}
            public function find(int $roomId): ?Room { return $roomId === 1 ? new Room(1, 'ひまわり', 0) : null; }
            public function nextDisplayNo(): int { return 1; }
            public function create(array $input, int $displayNo, ?string $createdBy, string $at): RoomSaveResult { return RoomSaveResult::saved(1); }
            public function rename(int $roomId, array $input, string $updatedBy, string $at): RoomSaveResult { return RoomSaveResult::saved(1); }
            public function softDelete(int $roomId, ?string $updatedBy, string $at): bool { return true; }
            public function memberDisplayOrders(int $roomId): array { return [10 => 1, 11 => 2]; }
            public function maxUserDisplayNo(): int { return 2; }
            public function setUserDisplayNo(int $userId, int $displayNo, string $updatedBy, string $at): void
            {
                if ($this->setThrows !== null) {
                    throw $this->setThrows;
                }
            }
        };
    }

    private function input(int $roomId = 1): UpdateRoomInput
    {
        return new UpdateRoomInput($roomId, ['c_room_name' => 'さくら'], [11, 10], new AuditActor('管理者', 1, 'admin', '10.0.0.1'), true, true);
    }

    public function testDbError_rollsBackAndRethrowsWithoutAudit(): void
    {
        $transaction = new SpyTransaction();
        $audit = new SpyAuditLog();
        $useCase = new UpdateRoomUseCase($this->repository(new \RuntimeException('接続断')), $transaction, $audit, new ClockFactory('Asia/Tokyo'));

        try {
            $useCase->execute($this->input());
            $this->fail('例外を投げ直すこと');
        } catch (\RuntimeException $e) {
            $this->assertSame('接続断', $e->getMessage());
        }
        $this->assertSame(['begin', 'rollback'], $transaction->calls);
        $this->assertSame([], $audit->records);
    }

    public function testSuccess_commitsAndRecordsRenameAndReorder(): void
    {
        $transaction = new SpyTransaction();
        $audit = new SpyAuditLog();
        $result = (new UpdateRoomUseCase($this->repository(null), $transaction, $audit, new ClockFactory('Asia/Tokyo')))->execute($this->input());

        $this->assertSame(2, $result->reordered);
        $this->assertSame(['begin', 'commit'], $transaction->calls);
        $this->assertSame(['room_update', 'room_member_reorder'], array_column($audit->records, 'action'));
        $this->assertSame(['room_name' => 'ひまわり', 'changed' => 2], $audit->records[1]['detail']);
    }

    public function testMissingRoom_throwsNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        (new UpdateRoomUseCase($this->repository(null), new SpyTransaction(), new SpyAuditLog(), new ClockFactory('Asia/Tokyo')))->execute($this->input(9));
    }
}
