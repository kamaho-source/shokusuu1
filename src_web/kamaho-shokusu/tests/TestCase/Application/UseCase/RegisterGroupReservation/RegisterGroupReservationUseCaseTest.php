<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\RegisterGroupReservation;

use App\Application\UseCase\RegisterGroupReservation\RegisterGroupReservationInput;
use App\Domain\Exception\ConflictException;
use App\Domain\Exception\UnauthorizedException;
use App\Domain\ValueObject\UserRole;
use App\Test\TestCase\Application\Fake\FakeUserRepository;
use App\Test\TestCase\Application\Fake\ReservationFixture;
use Cake\Chronos\Chronos;
use PHPUnit\Framework\TestCase;

/**
 * RegisterGroupReservationUseCase のテスト（Repository は Fake。DB を使わない）。
 *
 * 今日は 2026-11-01 に固定する。利用者 10 は職員（部屋 1・7・8 に所属）、20・21 は児童、30 は管理者。
 */
class RegisterGroupReservationUseCaseTest extends TestCase
{
    private const FAR  = '2026-11-20';
    private const NEAR = '2026-11-05';

    private ReservationFixture $f;

    protected function setUp(): void
    {
        parent::setUp();
        Chronos::setTestNow(new Chronos('2026-11-01 09:00:00', 'Asia/Tokyo'));
        $this->f = new ReservationFixture();
        $this->f->users->users[20] = FakeUserRepository::child();
        $this->f->users->users[21] = FakeUserRepository::child();
        $this->f->users->users[30] = FakeUserRepository::staff(UserRole::ADMIN);
        $this->f->users->names     = [20 => '児童A', 21 => '児童B'];
        $this->f->rooms->members   = array_merge($this->f->rooms->members, ['20:1', '21:1']);
        $this->f->rooms->names     = [1 => '部屋1', 9 => '退出した部屋'];
    }

    protected function tearDown(): void
    {
        Chronos::setTestNow(null);
        parent::tearDown();
    }

    /**
     * @param array<int, array<int, int>> $users 利用者ID => [食事区分 => 0/1]
     */
    private function register(array $users, int $login = 10, int $level = 0, bool $isAdmin = false, bool $isLeader = false, string $date = self::FAR): array
    {
        return $this->f->registerGroup()->execute(new RegisterGroupReservationInput(
            $date,
            ['i_id_room' => 1, 'users' => $users],
            [1 => '部屋1'],
            'テスト',
            null,
            $login,
            $isAdmin,
            $level,
            $isLeader,
        ))->toArray('/TReservationInfo/');
    }

    public function testStaffRegistersChildren_andForgetsCacheForAll(): void
    {
        $result = $this->register([20 => [1 => 1], 21 => [3 => 1]]);

        $this->assertSame('予約が正常に登録されました。', $result['message']);
        $this->assertCount(2, $this->f->reservations->added);
        $this->assertSame([['date' => self::FAR, 'userIds' => [20, 21]]], $this->f->cache->forgotten);
    }

    public function testLastMinuteNewRow_keepsOriginalPlanZero(): void
    {
        $this->register([20 => [1 => 1]], date: self::NEAR);

        $this->assertSame([0, 1], [$this->f->reservations->added[0]->eatFlag, $this->f->reservations->added[0]->changeFlag]);
    }

    public function testLoginUserUnknown_isRejected(): void
    {
        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('ログインユーザーを特定できないため予約を更新できません。');

        $this->register([20 => [1 => 1]], 0);
    }

    public function testChildCannotEditOthers(): void
    {
        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('他ユーザーの予約を更新する権限がありません。');

        $this->register([21 => [1 => 1]], 20, 1);
    }

    public function testAdminOutsideRoom_cannotEditOthers(): void
    {
        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('この部屋を操作する権限がありません。');

        $this->register([20 => [1 => 1]], 30, 0, true);
    }

    public function testRegisterRequiresTargetMembership(): void
    {
        $this->f->users->users[22] = FakeUserRepository::child();

        $this->expectException(UnauthorizedException::class);
        $this->expectExceptionMessage('対象ユーザーは指定された部屋に所属していません。');

        $this->register([22 => [1 => 1]]);
    }

    public function testCancelOnly_isAllowedForLeftTarget(): void
    {
        $this->f->users->users[22] = FakeUserRepository::child();
        $this->f->reservations->record(22, self::FAR, 1, 1);

        $this->register([22 => [1 => 0]]);

        $this->assertSame(0, $this->f->reservations->find(22, self::FAR, 1, 1)?->eatFlag);
    }

    public function testEffectiveRowInLeftRoom_isSkippedWithRoomName(): void
    {
        $this->f->reservations->record(20, self::FAR, 9, 1);

        $result = $this->register([20 => [1 => 1]]);

        $this->assertSame('一部の予約はすでに存在していたためスキップされました。', $result['message']);
        $this->assertSame([['user_name' => '児童A', 'meal_type' => '朝食', 'room_name' => '退出した部屋']], $result['data']['skipped']);
        $this->assertSame(1, $this->f->reservations->find(20, self::FAR, 9, 1)?->eatFlag, '退出した部屋の予約は黙って移さない');
    }

    public function testOptimisticConflict_rollsBackEarlierWrites(): void
    {
        $this->f->reservations->record(20, self::FAR, 1, 1);
        $this->f->reservations->record(21, self::FAR, 1, 1);
        $calls = 0;
        $this->f->reservations->onUpdate = static function () use (&$calls): bool {
            return ++$calls === 1;
        };

        try {
            $this->register([20 => [1 => 0], 21 => [1 => 0]]);
            $this->fail('例外が出ていない');
        } catch (ConflictException $e) {
            $this->assertSame('他の操作と競合しました。画面を再読み込みして再実行してください。', $e->getMessage());
        }
        $this->assertSame(1, $this->f->reservations->find(20, self::FAR, 1, 1)?->eatFlag, '1件目の取消も取り消される');
        $this->assertSame([], $this->f->cache->forgotten);
    }
}
