<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\ToggleMeal;

use App\Application\UseCase\ToggleMeal\ToggleMealInput;
use App\Domain\Exception\ConflictException;
use App\Domain\Exception\InvalidInputException;
use App\Domain\Exception\NotFoundException;
use App\Domain\Exception\UnauthorizedException;
use App\Domain\ValueObject\UserRole;
use App\Test\TestCase\Application\Fake\FakeUserRepository;
use App\Test\TestCase\Application\Fake\ReservationFixture;
use Cake\Chronos\Chronos;
use Cake\Chronos\ChronosDate;
use PHPUnit\Framework\TestCase;

/**
 * ToggleMealUseCase のテスト（Repository は Fake。DB を使わない）。
 *
 * 今日は 2026-11-01 に固定する（境界日 = 11/15、通常予約 = 11/16 以降）。
 */
class ToggleMealUseCaseTest extends TestCase
{
    private ReservationFixture $f;

    protected function setUp(): void
    {
        parent::setUp();
        Chronos::setTestNow(new Chronos('2026-11-01 09:00:00', 'Asia/Tokyo'));
        $this->f = new ReservationFixture();
        $this->f->users->users[20] = FakeUserRepository::child();
        $this->f->users->users[30] = FakeUserRepository::staff(UserRole::ADMIN);
        $this->f->rooms->members[] = '20:1';
    }

    protected function tearDown(): void
    {
        Chronos::setTestNow(null);
        parent::tearDown();
    }

    private function input(string $date, int $meal, int $value, int $target = 10, int $login = 10, bool $noSwap = false, bool $adminAnyRoom = false, int $room = 1): ToggleMealInput
    {
        return new ToggleMealInput($room, $date, $meal, $value, $target, $login, 'テスト', $noSwap, $adminAnyRoom);
    }

    public function testRegistersNormalDate_writesBothFlagsAndForgetsCache(): void
    {
        $out = $this->f->toggleMeal()->execute($this->input('2026-11-16', 3, 1));

        $this->assertTrue($out->value);
        $this->assertSame(['eatFlag' => 1, 'changeFlag' => 1], array_intersect_key($this->f->reservations->toggles[0], ['eatFlag' => 0, 'changeFlag' => 0]));
        $this->assertSame([['date' => '2026-11-16', 'userIds' => [10]]], $this->f->cache->forgotten);
    }

    public function testLastMinuteRegister_keepsOriginalPlanZero(): void
    {
        $this->f->toggleMeal()->execute($this->input('2026-11-15', 3, 1));

        $this->assertSame(0, $this->f->reservations->toggles[0]['eatFlag'], '直前期間の新規は当初予定 0');
        $this->assertSame(1, $this->f->reservations->toggles[0]['changeFlag']);
    }

    public function testLastMinuteChange_keepsExistingOriginalPlan(): void
    {
        $this->f->reservations->add(20, 1, '2026-11-10', 3, 1);

        $this->f->toggleMeal()->execute($this->input('2026-11-10', 3, 0, 20));

        $this->assertSame(1, $this->f->reservations->toggles[0]['eatFlag'], '当初予定は変えない');
        $this->assertSame(0, $this->f->reservations->toggles[0]['changeFlag']);
    }

    public function testRejectsPastDate(): void
    {
        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('過去日の予約は変更できません。');
        $this->f->toggleMeal()->execute($this->input('2026-10-31', 3, 1));
    }

    public function testRejectsNonexistentDateBeforePermissionChecks(): void
    {
        $this->expectException(InvalidInputException::class);
        $this->expectExceptionMessage('日付が正しくありません。');
        // 対象者が存在しなくても、暦にない日付を先に返す
        $this->f->toggleMeal()->execute($this->input('2026-02-30', 3, 1, 999));
    }

    public function testRejectsUnknownTargetUser(): void
    {
        $this->expectException(NotFoundException::class);
        $this->f->toggleMeal()->execute($this->input('2026-11-16', 3, 1, 999));
    }

    public function testStaffCannotCancelOwnLastMinuteReservation(): void
    {
        $this->f->reservations->add(10, 1, '2026-11-10', 3);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('職員は直前編集でのキャンセルはできません。');
        $this->f->toggleMeal()->execute($this->input('2026-11-10', 3, 0));
    }

    public function testStaffCannotSwapLunchAndBentoInLastMinute(): void
    {
        $this->f->reservations->add(10, 1, '2026-11-10', 4);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('お昼とお弁当を入れ替えられません');
        $this->f->toggleMeal()->execute($this->input('2026-11-10', 2, 1));
    }

    public function testNoSwap_stopsInsteadOfCancellingBento(): void
    {
        $this->f->reservations->add(10, 1, '2026-11-20', 4);

        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('お弁当を登録済みのため、昼食は登録しませんでした');
        $this->f->toggleMeal()->execute($this->input('2026-11-20', 2, 1, noSwap: true));
    }

    public function testChildCannotEditOthers(): void
    {
        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('他ユーザーの予約を更新する権限がありません。');
        $this->f->toggleMeal()->execute($this->input('2026-11-16', 3, 1, 10, 20));
    }

    public function testStaffEditsChildInSameRoom_butNotInRoomTheStaffDoesNotBelongTo(): void
    {
        $this->f->toggleMeal()->execute($this->input('2026-11-16', 3, 1, 20));
        $this->assertCount(1, $this->f->reservations->toggles);

        $this->f->rooms->members = ['20:1'];
        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('この部屋を操作する権限がありません。');
        $this->f->toggleMeal()->execute($this->input('2026-11-17', 3, 1, 20));
    }

    public function testAdminAnyRoom_allowsAdminOutsideOwnRooms_onlyWhenRequested(): void
    {
        // 管理者 30 はどの部屋にも所属していない
        $this->f->toggleMeal()->execute($this->input('2026-11-16', 3, 1, 20, 30, adminAnyRoom: true));
        $this->assertCount(1, $this->f->reservations->toggles);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('この部屋を操作する権限がありません。');
        $this->f->toggleMeal()->execute($this->input('2026-11-17', 3, 1, 20, 30));
    }

    public function testCancelWithoutRowOutsideMembership_isRejected_butLeftoverRowCanBeCancelled(): void
    {
        $this->f->rooms->members = ['10:1'];
        $this->f->users->users[21] = FakeUserRepository::child();
        $this->f->reservations->add(21, 1, '2026-11-20', 3);

        // 退出後に残った予約の取消はできる
        $this->f->toggleMeal()->execute($this->input('2026-11-20', 3, 0, 21));
        $this->assertCount(1, $this->f->reservations->toggles);

        // 行が無い取消は、所属外として拒否する
        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('対象ユーザーは指定された部屋に所属していません。');
        $this->f->toggleMeal()->execute($this->input('2026-11-21', 3, 0, 21));
    }

    public function testInvalidateCacheFalse_leavesCacheToCaller(): void
    {
        $input = new ToggleMealInput(1, '2026-11-16', 3, 1, 10, 10, 'テスト', invalidateCache: false);

        $this->f->toggleMeal()->execute($input);

        $this->assertSame([], $this->f->cache->forgotten);
    }

    public function testFromPayload_emptyIsBadRequest_andUserIdDefaultsToLoginUser(): void
    {
        $in = ToggleMealInput::fromPayload(1, ['date' => '2026-11-16', 'meal' => '3', 'value' => '1'], 10, 'テスト');
        $this->assertSame([10, 3, 1], [$in->targetUserId, $in->meal, $in->value]);

        try {
            ToggleMealInput::fromPayload(1, [], 10, 'テスト');
            $this->fail('空のリクエストは入力エラー');
        } catch (InvalidInputException $e) {
            $this->assertSame(400, $e->getStatusCode());
        }
    }

    public function testTodayFollowsClock(): void
    {
        // 今日を 11/16 にすると、11/16 は直前期間（当初予定 0）になる
        Chronos::setTestNow(new Chronos('2026-11-16 09:00:00', 'Asia/Tokyo'));
        $this->assertSame('2026-11-16', ChronosDate::today('Asia/Tokyo')->format('Y-m-d'));

        $this->f->toggleMeal()->execute($this->input('2026-11-16', 3, 1));

        $this->assertSame(0, $this->f->reservations->toggles[0]['eatFlag']);
    }
}
