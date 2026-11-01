<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\ChangeMealRoom;

use App\Application\UseCase\ChangeMealRoom\ChangeMealRoomInput;
use App\Application\UseCase\ChangeMealRoom\ChangeMealRoomUseCase;
use App\Domain\Exception\ConflictException;
use App\Domain\Exception\UnauthorizedException;
use App\Test\TestCase\Application\Fake\ReservationFixture;
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

    private ReservationFixture $f;

    protected function setUp(): void
    {
        parent::setUp();
        $this->f = new ReservationFixture();
        // 旧部屋 8 に夕食の予約がある
        $this->f->reservations->add(10, 8, '2099-06-01', 3);
    }

    private function useCase(): ChangeMealRoomUseCase
    {
        return new ChangeMealRoomUseCase($this->f->toggleMeal(), $this->f->reservations, $this->f->rooms, $this->f->cache);
    }

    public function testExecute_movesReservationToNewRoom(): void
    {
        $output = $this->useCase()->execute($this->createInput());

        $this->assertSame(3, $output->meal);
        $this->assertSame(8, $output->fromRoomId);
        $this->assertSame(7, $output->toRoomId);
        $this->assertSame([7], $this->f->reservations->effectiveRoomIds(10, '2099-06-01', 3));
        $this->assertSame(1, $this->f->reservations->transactions, '1トランザクションで行う');
        $this->assertSame([['date' => '2099-06-01', 'userIds' => [10]]], $this->f->cache->forgotten, 'キャッシュは最後に1回だけ捨てる');
    }

    public function testExecute_rollsBackWhenDestinationFails(): void
    {
        $this->f->reservations->onToggle = static function (int $meal, int $roomId): void {
            if ($roomId === 7) {
                throw new ConflictException('昼食と弁当は同時に予約できません。');
            }
        };

        try {
            $this->useCase()->execute($this->createInput());
            $this->fail('例外になるはず');
        } catch (ConflictException) {
        }
        $this->assertSame([8], $this->f->reservations->effectiveRoomIds(10, '2099-06-01', 3), '旧部屋の予約が残る');
        $this->assertSame([], $this->f->cache->forgotten);
    }

    public function testExecute_rejectsWhenSourceHasNoEffectiveReservation(): void
    {
        $this->expectException(ConflictException::class);
        $this->expectExceptionMessage('変更前の部屋の予約が見つかりません。');
        $this->useCase()->execute($this->createInput(1, 7));
    }

    public function testExecute_rewordsStaffLastMinuteCancelAsRoomChange(): void
    {
        // 直前期間（今日）の職員の取消は ToggleMealUseCase が拒否する → 部屋変更の文言に言い換える
        $today = \Cake\Chronos\ChronosDate::today('Asia/Tokyo')->format('Y-m-d');
        $this->f->reservations->add(10, 8, $today, 3);
        $input = new ChangeMealRoomInput($today, 3, 8, 7, 10, 'テストユーザー', 10);

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('直前期間（14日以内）は職員の部屋変更はできません。');
        $this->useCase()->execute($input);
    }

    public function testExecute_rejectsMovingFromRoomTheUserLeft(): void
    {
        $this->f->rooms->members = ['10:7'];

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('所属外・削除済みの部屋の予約は部屋を変更できません。');
        $this->useCase()->execute($this->createInput());
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
