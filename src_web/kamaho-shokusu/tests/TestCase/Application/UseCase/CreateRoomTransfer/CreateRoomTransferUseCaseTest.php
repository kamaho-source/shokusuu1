<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\CreateRoomTransfer;

use App\Application\UseCase\CreateRoomTransfer\CreateRoomTransferInput;
use App\Application\UseCase\CreateRoomTransfer\CreateRoomTransferOutput;
use App\Application\UseCase\CreateRoomTransfer\CreateRoomTransferUseCase;
use App\Domain\Entity\RoomTransferSchedule;
use App\Domain\Repository\RoomTransferScheduleRepositoryInterface;
use PHPUnit\Framework\TestCase;
use Psr\Clock\ClockInterface;

/**
 * CreateRoomTransferUseCase の有効開始日の確認（暦にある日付・今日以降）。今日は 2026-11-02（Asia/Tokyo）に固定する。
 */
class CreateRoomTransferUseCaseTest extends TestCase
{
    /** create の呼び出しを記録する（既存の Fake は呼び出しを残さないため） */
    private RoomTransferScheduleRepositoryInterface $schedules;

    private CreateRoomTransferUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->schedules = new class implements RoomTransferScheduleRepositoryInterface {
            /** @var list<array<string, mixed>> */
            public array $created = [];

            public function create(array $values, string $createdBy, string $at): array
            {
                $this->created[] = $values + ['by' => $createdBy, 'at' => $at];

                return [];
            }

            public function find(int $scheduleId): ?RoomTransferSchedule
            {
                return null;
            }

            public function changeStatus(int $scheduleId, int $status, string $updatedBy, string $at): void
            {
            }

            public function pendingUntil(string $date): array
            {
                return [];
            }
        };
        // 日付の変わり目の直後（東京で 00:05、UTC では前日）にして、時刻帯の取り違えも拾う
        $clock = new class implements ClockInterface {
            public function now(): \DateTimeImmutable
            {
                return new \DateTimeImmutable('2026-11-02 00:05:00', new \DateTimeZone('Asia/Tokyo'));
            }
        };
        $this->useCase = new CreateRoomTransferUseCase($this->schedules, $clock);
    }

    private function create(string $date): ?string
    {
        return $this->useCase->execute(new CreateRoomTransferInput(2, 1, 3, $date, 'tester'))->message;
    }

    public function testOutput_statusTellsCreatedOrRejected(): void
    {
        $this->assertSame(CreateRoomTransferOutput::CREATED, $this->useCase->execute(new CreateRoomTransferInput(2, 1, 3, '2026-11-02', 'tester'))->status);
        $rejected = $this->useCase->execute(new CreateRoomTransferInput(2, 1, 3, '2026-11-01', 'tester'));
        $this->assertSame(CreateRoomTransferOutput::REJECTED, $rejected->status);
        $this->assertSame('有効開始日は今日以降の日付を指定してください。', $rejected->message);
    }

    public function testYesterday_isRejectedAndNotSaved(): void
    {
        $this->assertSame('有効開始日は今日以降の日付を指定してください。', $this->create('2026-11-01'));
        $this->assertSame([], $this->schedules->created);
    }

    public function testToday_isSaved(): void
    {
        $this->assertNull($this->create('2026-11-02'));
        $this->assertCount(1, $this->schedules->created);
        $this->assertSame('2026-11-02', $this->schedules->created[0]['d_effective_date']);
        $this->assertSame('2026-11-02 00:05:00', $this->schedules->created[0]['at']);
    }

    /** @return array<string, array{string}> */
    public static function notCalendarDates(): array
    {
        return [
            'Feb 30' => ['2026-02-30'],
            'month 13' => ['2026-13-01'],
            'not a date' => ['not-a-date'],
        ];
    }

    /** @dataProvider notCalendarDates */
    public function testNotCalendarDate_isRejectedAndNotSaved(string $date): void
    {
        $this->assertSame('有効開始日が正しくありません。', $this->create($date));
        $this->assertSame([], $this->schedules->created);
    }

    public function testEmptyDate_isRequiredError(): void
    {
        $this->assertSame('ユーザー・異動先部屋・有効開始日は必須です。', $this->create(''));
        $this->assertSame([], $this->schedules->created);
    }
}
