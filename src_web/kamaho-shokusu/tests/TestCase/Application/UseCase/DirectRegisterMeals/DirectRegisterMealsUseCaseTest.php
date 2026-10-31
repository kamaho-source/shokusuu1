<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\DirectRegisterMeals;

use App\Application\UseCase\DirectRegisterMeals\DirectRegisterMealsInput;
use App\Application\UseCase\DirectRegisterMeals\DirectRegisterMealsUseCase;
use App\Domain\Exception\ConflictException;
use App\Domain\Exception\DuplicateMealConflictException;
use App\Domain\Exception\PersistenceException;
use App\Domain\Exception\UnauthorizedException;
use App\Service\ReservationWriteService;
use Cake\TestSuite\TestCase;

/**
 * DirectRegisterMealsUseCase のテスト。
 */
class DirectRegisterMealsUseCaseTest extends TestCase
{
    private function createUseCase(ReservationWriteService $writeService): DirectRegisterMealsUseCase
    {
        return new DirectRegisterMealsUseCase($writeService);
    }

    private function createInput(array $meals = [1, 2]): DirectRegisterMealsInput
    {
        return new DirectRegisterMealsInput(
            date:          '2099-06-01',
            roomId:        1,
            loginUserId:   10,
            loginUserName: 'テストユーザー',
            targetUserId:  10,
            mealIndices:   $meals,
        );
    }

    public function testExecute_registersAllMealsOnSuccess(): void
    {
        $writeService = $this->createMock(ReservationWriteService::class);
        $writeService->expects($this->exactly(2))
            ->method('processToggle')
            ->willReturn(['value' => true, 'details' => []]);

        $output = $this->createUseCase($writeService)->execute($this->createInput([1, 2]));

        $this->assertSame([1, 2], $output->registered);
        $this->assertSame([], $output->skipped);
    }

    public function testExecute_skipsMealConflictException(): void
    {
        $writeService = $this->createMock(ReservationWriteService::class);
        $writeService->expects($this->exactly(2))
            ->method('processToggle')
            ->willReturnCallback(function () use (&$callCount) {
                $callCount = ($callCount ?? 0) + 1;
                if ($callCount === 1) {
                    return ['value' => true, 'details' => []];
                }
                throw new ConflictException('昼食と弁当は同時に予約できません。');
            });

        $output = $this->createUseCase($writeService)->execute($this->createInput([1, 2]));

        $this->assertSame([1], $output->registered);
        $this->assertSame([2], $output->skipped);
    }

    public function testExecute_rethrowsUnauthorizedException(): void
    {
        $writeService = $this->createMock(ReservationWriteService::class);
        $writeService->expects($this->once())
            ->method('processToggle')
            ->willThrowException(new UnauthorizedException('他ユーザーの予約を更新する権限がありません。'));

        $this->expectException(UnauthorizedException::class);
        $this->createUseCase($writeService)->execute($this->createInput([1]));
    }

    public function testExecute_rethrowsPersistenceException(): void
    {
        $writeService = $this->createMock(ReservationWriteService::class);
        $writeService->expects($this->once())
            ->method('processToggle')
            ->willThrowException(new PersistenceException('Internal Server Error'));

        $this->expectException(PersistenceException::class);
        $this->createUseCase($writeService)->execute($this->createInput([1]));
    }

    public function testExecute_rethrowsOptimisticLockConflict(): void
    {
        $writeService = $this->createMock(ReservationWriteService::class);
        $writeService->expects($this->once())
            ->method('processToggle')
            ->willThrowException(new ConflictException('他の操作と競合しました。画面を再読み込みして再実行してください。'));

        $this->expectException(ConflictException::class);
        $this->createUseCase($writeService)->execute($this->createInput([1]));
    }

    public function testExecute_skipsMealReservedInAnotherRoom(): void
    {
        $writeService = $this->createMock(ReservationWriteService::class);
        $writeService->expects($this->exactly(3))
            ->method('processToggle')
            ->willReturnCallback(function (int $roomId, array $payload): array {
                if ((int)$payload['meal'] === 2) {
                    throw new DuplicateMealConflictException();
                }

                return ['value' => true, 'details' => []];
            });

        $output = $this->createUseCase($writeService)->execute($this->createInput([1, 2, 3]));

        $this->assertSame([1, 3], $output->registered);
        $this->assertSame([2], $output->skipped);
    }

    /** 途中の食事が失敗しても、先に登録した食事は registered に入れ、失敗した食事は理由つきで返す */
    public function testExecute_returnsPartialResultWhenLaterMealFails(): void
    {
        $writeService = $this->createMock(ReservationWriteService::class);
        $writeService->expects($this->exactly(3))
            ->method('processToggle')
            ->willReturnCallback(function (int $roomId, array $payload): array {
                if ((int)$payload['meal'] === 2) {
                    throw new UnauthorizedException('職員は直前編集でのキャンセルはできないため、お昼とお弁当を入れ替えられません。');
                }

                return ['value' => true, 'details' => []];
            });

        $output = $this->createUseCase($writeService)->execute($this->createInput([1, 2, 3]));

        $this->assertSame([1, 3], $output->registered);
        $this->assertSame([], $output->skipped);
        $this->assertSame([['meal' => 2, 'message' => '職員は直前編集でのキャンセルはできないため、お昼とお弁当を入れ替えられません。']], $output->failed);
    }

    /** 入れ替えない指定を processToggle に渡す */
    public function testExecute_passesNoSwapToWriteService(): void
    {
        $writeService = $this->createMock(ReservationWriteService::class);
        $writeService->expects($this->once())
            ->method('processToggle')
            ->with(1, $this->callback(fn(array $p): bool => $p['noSwap'] === true && $p['meal'] === 2))
            ->willReturn(['value' => true, 'details' => []]);

        $input = new DirectRegisterMealsInput(
            date: '2099-06-01', roomId: 1, loginUserId: 10, loginUserName: 'テストユーザー', targetUserId: 10,
            mealIndices: [2], skipLunchBentoSwap: true,
        );
        $this->assertSame([2], $this->createUseCase($writeService)->execute($input)->registered);
    }

    /** 全部の食事が失敗したら、従来どおり最初の例外を投げる */
    public function testExecute_rethrowsFirstExceptionWhenAllMealsFail(): void
    {
        $writeService = $this->createMock(ReservationWriteService::class);
        $writeService->method('processToggle')
            ->willReturnCallback(function (int $roomId, array $payload): array {
                throw new ConflictException('失敗' . $payload['meal']);
            });

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('失敗1');
        $this->createUseCase($writeService)->execute($this->createInput([1, 3]));
    }

    /** スキップと失敗が混ざったときは例外にせず、両方を返す */
    public function testExecute_returnsSkippedAndFailedTogether(): void
    {
        $writeService = $this->createMock(ReservationWriteService::class);
        $writeService->method('processToggle')
            ->willReturnCallback(function (int $roomId, array $payload): array {
                throw (int)$payload['meal'] === 1 ? new DuplicateMealConflictException() : new ConflictException('承認済みの予約は変更できません。');
            });

        $output = $this->createUseCase($writeService)->execute($this->createInput([1, 3]));

        $this->assertSame([], $output->registered);
        $this->assertSame([1], $output->skipped);
        $this->assertSame([['meal' => 3, 'message' => '承認済みの予約は変更できません。']], $output->failed);
    }
}
