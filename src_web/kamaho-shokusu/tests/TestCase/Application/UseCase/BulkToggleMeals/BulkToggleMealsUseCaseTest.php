<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\BulkToggleMeals;

use App\Application\UseCase\BulkToggleMeals\BulkToggleMealsUseCase;
use App\Domain\Exception\PersistenceException;
use App\Test\TestCase\Application\Fake\ReservationFixture;
use PHPUnit\Framework\TestCase;

/**
 * BulkToggleMealsUseCase のテスト（Repository は Fake）。
 */
class BulkToggleMealsUseCaseTest extends TestCase
{
    public function testCancelsFirst_returnsResultsInSentOrder_andForgetsCacheOncePerDate(): void
    {
        $f = new ReservationFixture();
        $f->reservations->add(10, 8, '2099-06-01', 3);
        $useCase = new BulkToggleMealsUseCase($f->toggleMeal(), $f->cache);

        $results = $useCase->execute([
            ['roomId' => 7, 'date' => '2099-06-01', 'meal' => 3, 'value' => 1],
            ['roomId' => 8, 'date' => '2099-06-01', 'meal' => 3, 'value' => 0],
            ['roomId' => 1, 'date' => '2099-06-02', 'meal' => 1, 'value' => 1],
        ], 10, 'テスト');

        $this->assertSame([true, true, true], array_column($results, 'ok'));
        $this->assertSame([0, 1, 2], array_keys($results), '送った順番');
        $this->assertSame([8, 7, 1], array_column($f->reservations->toggles, 'roomId'), '取消（部屋 8）を先に処理する');
        $this->assertSame(
            [['date' => '2099-06-01', 'userIds' => [10, 10]], ['date' => '2099-06-02', 'userIds' => [10]]],
            $f->cache->forgotten
        );
    }

    public function testOneFailureDoesNotStopOthers(): void
    {
        $f = new ReservationFixture();
        $f->reservations->onToggle = static function (int $meal): void {
            if ($meal === 2) {
                throw new PersistenceException('予約の更新に失敗しました（システムエラーのため。管理者にお問い合わせください [エラーコード: SYS-0000]）');
            }
        };

        $results = (new BulkToggleMealsUseCase($f->toggleMeal(), $f->cache))->execute([
            ['roomId' => 1, 'date' => '2099-06-01', 'meal' => 1, 'value' => 1],
            ['roomId' => 1, 'date' => '2099-06-01', 'meal' => 2, 'value' => 1],
            ['roomId' => 1, 'date' => '2020-01-01', 'meal' => 3, 'value' => 1],
        ], 10, 'テスト');

        $this->assertTrue($results[0]['ok']);
        $this->assertStringContainsString('SYS-0000', $results[1]['message']);
        $this->assertSame('過去日の予約は変更できません。', $results[2]['message']);
    }
}
