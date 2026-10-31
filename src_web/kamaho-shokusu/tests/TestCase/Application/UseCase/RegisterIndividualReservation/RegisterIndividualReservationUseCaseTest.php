<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\RegisterIndividualReservation;

use App\Application\UseCase\RegisterIndividualReservation\RegisterIndividualReservationInput;
use App\Domain\Exception\ConflictException;
use App\Domain\Exception\InvalidInputException;
use App\Domain\Exception\PersistenceException;
use App\Domain\Exception\ReservationValidationException;
use App\Domain\Exception\UnauthorizedException;
use App\Domain\ValueObject\UserRole;
use App\Test\TestCase\Application\Fake\FakeUserRepository;
use App\Test\TestCase\Application\Fake\ReservationFixture;
use Cake\Chronos\Chronos;
use PHPUnit\Framework\TestCase;

/**
 * RegisterIndividualReservationUseCase のテスト（Repository は Fake。DB を使わない）。
 *
 * 今日は 2026-11-01 に固定する（直前期間 = 11/15 まで、それより先は通常）。利用者 10 は職員、20 は児童。
 */
class RegisterIndividualReservationUseCaseTest extends TestCase
{
    private const FAR  = '2026-11-20';
    private const NEAR = '2026-11-05';

    private const ROOMS = [1 => '部屋1', 7 => '部屋7'];

    private ReservationFixture $f;

    protected function setUp(): void
    {
        parent::setUp();
        Chronos::setTestNow(new Chronos('2026-11-01 09:00:00', 'Asia/Tokyo'));
        $this->f = new ReservationFixture();
        $this->f->users->users[20] = FakeUserRepository::child();
        $this->f->rooms->names     = [1 => '部屋1', 7 => '部屋7', 9 => '退出した部屋'];
    }

    protected function tearDown(): void
    {
        Chronos::setTestNow(null);
        parent::tearDown();
    }

    /**
     * @param array<int|string, array<int|string, int>> $meals
     */
    private function register(array $meals, string $date = self::FAR, int $userId = 20, ?string $dateError = null): array
    {
        return $this->f->registerIndividual()->execute(new RegisterIndividualReservationInput(
            $date,
            ['meals' => $meals],
            self::ROOMS,
            $userId,
            'テスト',
            $dateError,
        ))->toArray('/TReservationInfo/');
    }

    public function testNewMeals_farDateWritesPlanAndForgetsCache(): void
    {
        $result = $this->register([1 => [1 => 1], 3 => [7 => 1]]);

        $this->assertSame('個人予約が正常に登録されました。', $result['message']);
        $this->assertSame([1, 1], [$this->f->reservations->added[0]->eatFlag, $this->f->reservations->added[0]->changeFlag]);
        $this->assertSame(7, $this->f->reservations->added[1]->roomId);
        $this->assertTrue($result['data']['details']['breakfast']);
        $this->assertTrue($result['data']['details']['dinner']);
        $this->assertSame([['date' => self::FAR, 'userIds' => [20]]], $this->f->cache->forgotten);
        $this->assertSame(1, $this->f->reservations->commits);
    }

    public function testNewMeal_lastMinuteKeepsOriginalPlanZero(): void
    {
        $this->register([1 => [1 => 1]], self::NEAR);

        $this->assertSame([0, 1], [$this->f->reservations->added[0]->eatFlag, $this->f->reservations->added[0]->changeFlag]);
    }

    public function testAlreadyEffective_isSkippedWithNotice(): void
    {
        $this->f->reservations->record(20, self::FAR, 1, 1);

        $result = $this->register([1 => [1 => 1]]);

        $this->assertSame('一部の予約は既に存在するため、スキップされました。', $result['message']);
        $this->assertSame([['reservation_date' => self::FAR, 'meal_type' => 1, 'room_id' => 1]], $result['data']['skipped']);
        $this->assertSame([], $this->f->reservations->added);
    }

    public function testDateError_isRejectedBeforeAnyWrite(): void
    {
        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('過去日は予約できません。');

        $this->register([1 => [1 => 1]], self::FAR, 20, '過去日は予約できません。');
    }

    public function testInvalidJson_is400(): void
    {
        try {
            $this->f->registerIndividual()->execute(new RegisterIndividualReservationInput(self::FAR, '{broken', self::ROOMS, 20, 'テスト', null));
            $this->fail('例外が出ていない');
        } catch (InvalidInputException $e) {
            $this->assertSame(['データの形式が不正です。', 400], [$e->getMessage(), $e->getStatusCode()]);
        }
    }

    public function testRoomOutsideScreen_isUnauthorized(): void
    {
        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('選択された部屋は権限がありません。');

        $this->register([1 => [9 => 1]]);
    }

    public function testTwoRoomsForOneMeal_isConflict(): void
    {
        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('同じ食事区分に対して複数の部屋を選択することはできません。');

        $this->register([1 => [1 => 1, 7 => 1]]);
    }

    public function testStaffCannotCancelInLastMinute_andNothingIsWritten(): void
    {
        $this->f->reservations->record(10, self::NEAR, 1, 3);

        try {
            $this->register([3 => [1 => 0]], self::NEAR, 10);
            $this->fail('例外が出ていない');
        } catch (UnauthorizedException $e) {
            $this->assertSame('職員は直前編集でのキャンセルはできません。', $e->getMessage());
        }
        $this->assertSame([], $this->f->reservations->updates);
        $this->assertSame(1, $this->f->reservations->rollbacks);
    }

    public function testAdminStaffCanCancelInLastMinute_keepingOriginalPlan(): void
    {
        $this->f->users->users[10] = FakeUserRepository::staff(UserRole::ADMIN);
        $this->f->reservations->record(10, self::NEAR, 1, 3);

        $this->register([3 => [1 => 0]], self::NEAR, 10);

        $this->assertSame(['changeFlag' => 0, 'eatFlag' => null], array_intersect_key($this->f->reservations->updates[0], ['changeFlag' => 0, 'eatFlag' => 0]));
    }

    public function testStaffCannotMoveRoomInLastMinute(): void
    {
        $this->f->reservations->record(10, self::NEAR, 7, 3);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('直前期間（14日以内）は職員の部屋変更はできません。');

        $this->register([3 => [1 => 1]], self::NEAR, 10);
    }

    public function testMoveFromOtherScreenRoom_cancelsOldAndCreatesNew(): void
    {
        $this->f->reservations->record(20, self::FAR, 7, 3);

        $this->register([3 => [1 => 1]]);

        $this->assertSame(0, $this->f->reservations->find(20, self::FAR, 7, 3)?->eatFlag);
        $this->assertSame(1, $this->f->reservations->find(20, self::FAR, 1, 3)?->eatFlag);
    }

    public function testEffectiveRowInRoomOutsideScreen_cannotBeMoved(): void
    {
        $this->f->reservations->record(20, self::FAR, 9, 3);

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('「退出した部屋」に同じ食事の予約があります。');

        $this->register([3 => [1 => 1]]);
    }

    public function testCancelOnlyOutsideScreen_returnsReason(): void
    {
        $this->f->reservations->record(20, self::FAR, 9, 3);

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('「退出した部屋」の予約は、この画面では取り消せません。');

        $this->register([3 => [1 => 0]]);
    }

    public function testBentoOutsideScreen_blocksLunch(): void
    {
        $this->f->reservations->record(20, self::FAR, 9, 4);

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('「退出した部屋」に弁当の予約があるため、昼食は予約できません');

        $this->register([2 => [1 => 1]]);
    }

    public function testLunchBentoValidationOnSave_isConflictAndRolledBack(): void
    {
        $this->f->reservations->record(20, self::FAR, 1, 1, 0, 0);
        $this->f->reservations->onAdd = static function (): void {
            throw new ReservationValidationException('昼食と弁当は同時に予約できません。どちらか一方にしてください。', [], '{}');
        };

        try {
            $this->register([1 => [1 => 1], 2 => [1 => 1]]);
            $this->fail('例外が出ていない');
        } catch (ConflictException $e) {
            $this->assertSame('昼食と弁当は同時に予約できません。どちらか一方にしてください。', $e->getMessage());
        }
        // 先に有効にした朝食も取り消されている
        $this->assertSame(0, $this->f->reservations->find(20, self::FAR, 1, 1)?->eatFlag);
    }

    public function testOtherValidationError_isPersistenceError(): void
    {
        $this->f->reservations->onAdd = static function (): void {
            throw new ReservationValidationException(null, ['だめ'], '{}');
        };

        $this->expectException(PersistenceException::class);
        $this->expectExceptionMessage('予約の登録中にエラーが発生しました。');

        $this->register([1 => [1 => 1]]);
    }

    public function testOptimisticConflict_isConflict(): void
    {
        $this->f->reservations->record(20, self::FAR, 1, 1, 0, 0);
        $this->f->reservations->onUpdate = static fn(): bool => false;

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('他の操作と競合しました。');

        $this->register([1 => [1 => 1]]);
    }

    public function testDbFailure_isConflictWithReason(): void
    {
        $this->f->reservations->onAdd = static function (): void {
            throw new \RuntimeException('接続が切れました');
        };

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('接続が切れました');

        $this->register([1 => [1 => 1]]);
    }

    public function testNothingChanged_isSystemError(): void
    {
        $this->expectException(PersistenceException::class);
        $this->expectExceptionMessage('システムエラーが発生しました。');

        $this->register([1 => [1 => 0]]);
    }
}
