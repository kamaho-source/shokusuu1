<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\DirectRegisterMeals;

use App\Application\UseCase\DirectRegisterMeals\DirectRegisterMealsInput;
use App\Application\UseCase\DirectRegisterMeals\DirectRegisterMealsUseCase;
use App\Domain\Exception\ConflictException;
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
}
