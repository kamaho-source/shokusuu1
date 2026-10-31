<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\DirectRegisterMeals;

use App\Application\UseCase\DirectRegisterMeals\DirectRegisterMealsInput;
use App\Application\UseCase\DirectRegisterMeals\DirectRegisterMealsUseCase;
use App\Domain\Exception\ConflictException;
use App\Domain\Exception\DuplicateMealConflictException;
use App\Domain\Exception\PersistenceException;
use App\Domain\Exception\UnauthorizedException;
use App\Test\TestCase\Application\Fake\ReservationFixture;
use PHPUnit\Framework\TestCase;

/**
 * DirectRegisterMealsUseCase のテスト（Repository は Fake）。
 */
class DirectRegisterMealsUseCaseTest extends TestCase
{
    private ReservationFixture $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = new ReservationFixture();
    }

    private function useCase(): DirectRegisterMealsUseCase
    {
        return new DirectRegisterMealsUseCase($this->f->toggleMeal());
    }

    private function createInput(array $meals = [1, 2], bool $skipSwap = false): DirectRegisterMealsInput
    {
        return new DirectRegisterMealsInput(
            date:          '2099-06-01',
            roomId:        1,
            loginUserId:   10,
            loginUserName: 'テストユーザー',
            targetUserId:  10,
            mealIndices:   $meals,
            skipLunchBentoSwap: $skipSwap,
        );
    }

    /** 食事 $failMeal の保存で $e を投げる */
    private function failOn(int $failMeal, \Throwable $e): void
    {
        $this->f->reservations->onToggle = static function (int $meal) use ($failMeal, $e): void {
            if ($meal === $failMeal) {
                throw $e;
            }
        };
    }

    public function testExecute_registersAllMealsOnSuccess(): void
    {
        $output = $this->useCase()->execute($this->createInput([1, 2]));

        $this->assertSame([1, 2], $output->registered);
        $this->assertSame([], $output->skipped);
        $this->assertCount(2, $this->f->reservations->toggles);
    }

    public function testExecute_skipsMealConflictException(): void
    {
        $this->failOn(2, new ConflictException('昼食と弁当は同時に予約できません。'));

        $output = $this->useCase()->execute($this->createInput([1, 2]));

        $this->assertSame([1], $output->registered);
        $this->assertSame([2], $output->skipped);
    }

    public function testExecute_rethrowsUnauthorizedException(): void
    {
        // 部屋 2 には所属していない
        $input = new DirectRegisterMealsInput('2099-06-01', 2, 10, 'テストユーザー', 11, [1]);
        $this->f->users->users[11] = $this->f->users::staff();

        $this->expectException(UnauthorizedException::class);
        $this->useCase()->execute($input);
    }

    public function testExecute_rethrowsPersistenceException(): void
    {
        $this->failOn(1, new PersistenceException('Internal Server Error'));

        $this->expectException(PersistenceException::class);
        $this->useCase()->execute($this->createInput([1]));
    }

    public function testExecute_rethrowsOptimisticLockConflict(): void
    {
        $this->failOn(1, new ConflictException('他の操作と競合しました。画面を再読み込みして再実行してください。'));

        $this->expectException(ConflictException::class);
        $this->useCase()->execute($this->createInput([1]));
    }

    public function testExecute_skipsMealReservedInAnotherRoom(): void
    {
        $this->failOn(2, new DuplicateMealConflictException());

        $output = $this->useCase()->execute($this->createInput([1, 2, 3]));

        $this->assertSame([1, 3], $output->registered);
        $this->assertSame([2], $output->skipped);
    }

    /** 途中の食事が失敗しても、先に登録した食事は registered に入れ、失敗した食事は理由つきで返す */
    public function testExecute_returnsPartialResultWhenLaterMealFails(): void
    {
        $this->failOn(2, new UnauthorizedException('職員は直前編集でのキャンセルはできないため、お昼とお弁当を入れ替えられません。'));

        $output = $this->useCase()->execute($this->createInput([1, 2, 3]));

        $this->assertSame([1, 3], $output->registered);
        $this->assertSame([], $output->skipped);
        $this->assertSame([['meal' => 2, 'message' => '職員は直前編集でのキャンセルはできないため、お昼とお弁当を入れ替えられません。']], $output->failed);
    }

    /** 入れ替えない指定なら、同じ部屋のお弁当が登録済みの昼食は登録せずスキップする */
    public function testExecute_noSwap_skipsLunchWhenBentoAlreadyInRoom(): void
    {
        $this->f->reservations->add(10, 1, '2099-06-01', 4);

        $output = $this->useCase()->execute($this->createInput([2], true));

        $this->assertSame([], $output->registered);
        $this->assertSame([2], $output->skipped);
        $this->assertSame([], $this->f->reservations->toggles, '昼食は登録しない');
    }

    /** 入れ替えない指定が無ければ、昼食を登録する（お弁当の取消は保存側が行う） */
    public function testExecute_withoutNoSwap_registersLunchEvenIfBentoInRoom(): void
    {
        $this->f->reservations->add(10, 1, '2099-06-01', 4);

        $this->assertSame([2], $this->useCase()->execute($this->createInput([2]))->registered);
    }

    /** 全部の食事が失敗したら、従来どおり最初の例外を投げる */
    public function testExecute_rethrowsFirstExceptionWhenAllMealsFail(): void
    {
        $this->f->reservations->onToggle = static function (int $meal): void {
            throw new ConflictException('失敗' . $meal);
        };

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('失敗1');
        $this->useCase()->execute($this->createInput([1, 3]));
    }

    /** スキップと失敗が混ざったときは例外にせず、両方を返す */
    public function testExecute_returnsSkippedAndFailedTogether(): void
    {
        $this->f->reservations->onToggle = static function (int $meal): void {
            throw $meal === 1 ? new DuplicateMealConflictException() : new ConflictException('承認済みの予約は変更できません。');
        };

        $output = $this->useCase()->execute($this->createInput([1, 3]));

        $this->assertSame([], $output->registered);
        $this->assertSame([1], $output->skipped);
        $this->assertSame([['meal' => 3, 'message' => '承認済みの予約は変更できません。']], $output->failed);
    }
}
