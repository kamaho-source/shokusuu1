<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\ChangeMealRoom;

use App\Application\UseCase\ChangeMealRoom\ChangeMealRoomInput;
use App\Application\UseCase\ChangeMealRoom\ChangeMealRoomUseCase;
use App\Domain\Exception\ConflictException;
use App\Domain\Exception\UnauthorizedException;
use App\Service\ReservationWriteService;
use Cake\TestSuite\TestCase;

/**
 * ChangeMealRoomUseCase のテスト。
 */
class ChangeMealRoomUseCaseTest extends TestCase
{
    private function createInput(int $from = 8, int $to = 7, int $meal = 3): ChangeMealRoomInput
    {
        return new ChangeMealRoomInput(
            date:          '2099-06-01',
            meal:          $meal,
            fromRoomId:    $from,
            toRoomId:      $to,
            loginUserId:   10,
            loginUserName: 'テストユーザー',
            targetUserId:  10,
        );
    }

    public function testExecute_movesReservationToNewRoom(): void
    {
        $writeService = $this->createMock(ReservationWriteService::class);
        $writeService->expects($this->once())
            ->method('processRoomChange')
            ->with(8, 7, ['date' => '2099-06-01', 'meal' => 3, 'userId' => 10], 10, 'テストユーザー')
            ->willReturn(['value' => true, 'details' => [], 'affected' => []]);

        $output = (new ChangeMealRoomUseCase($writeService))->execute($this->createInput());

        $this->assertSame(3, $output->meal);
        $this->assertSame(8, $output->fromRoomId);
        $this->assertSame(7, $output->toRoomId);
    }

    public function testExecute_rewordsStaffLastMinuteCancelAsRoomChange(): void
    {
        $writeService = $this->createMock(ReservationWriteService::class);
        $writeService->method('processRoomChange')
            ->willThrowException(new UnauthorizedException('職員は直前編集でのキャンセルはできません。'));

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('直前期間（14日以内）は職員の部屋変更はできません。');
        (new ChangeMealRoomUseCase($writeService))->execute($this->createInput());
    }

    public function testExecute_rethrowsOtherUnauthorizedAsIs(): void
    {
        $writeService = $this->createMock(ReservationWriteService::class);
        $writeService->method('processRoomChange')
            ->willThrowException(new UnauthorizedException('対象ユーザーは指定された部屋に所属していません。'));

        $this->expectExceptionMessage('対象ユーザーは指定された部屋に所属していません。');
        (new ChangeMealRoomUseCase($writeService))->execute($this->createInput());
    }

    public function testExecute_rethrowsConflict(): void
    {
        $writeService = $this->createMock(ReservationWriteService::class);
        $writeService->method('processRoomChange')
            ->willThrowException(new ConflictException('昼食と弁当は同時に予約できません。'));

        $this->expectException(ConflictException::class);
        (new ChangeMealRoomUseCase($writeService))->execute($this->createInput(8, 7, 2));
    }

    public function testInput_rejectsSameRoom(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->createInput(7, 7);
    }

    public function testInput_rejectsInvalidMeal(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->createInput(8, 7, 5);
    }

    /** 暦にない日付は DB へ送る前に入力エラーにする（以前は SQL の日付エラーで 500 になっていた） */
    public function testInput_rejectsNonexistentDate(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('日付が正しくありません。');
        new ChangeMealRoomInput(
            date: '2027-02-30', meal: 3, fromRoomId: 8, toRoomId: 7,
            loginUserId: 10, loginUserName: 'テストユーザー', targetUserId: 10,
        );
    }
}
