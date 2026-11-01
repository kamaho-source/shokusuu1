<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\BulkChangeEditReservations;

use App\Application\UseCase\BulkChangeEditReservations\BulkChangeEditReservationsInput;
use App\Domain\ValueObject\UserRole;
use App\Test\TestCase\Application\Fake\FakeUserRepository;
use App\Test\TestCase\Application\Fake\ReservationFixture;
use Cake\Chronos\Chronos;
use PHPUnit\Framework\TestCase;

/**
 * BulkChangeEditReservationsUseCase のテスト（Repository は Fake。DB を使わない）。
 *
 * 今日は 2026-11-01 に固定する（直前期間 = 11/15 まで）。利用者 10 は職員、20・21 は児童、30 は管理者（いずれも部屋 1）。
 */
class BulkChangeEditReservationsUseCaseTest extends TestCase
{
    private const NEAR = '2026-11-05';
    private const FAR  = '2026-11-20';
    private const PAST = '2026-10-30';

    private ReservationFixture $f;

    protected function setUp(): void
    {
        parent::setUp();
        Chronos::setTestNow(new Chronos('2026-11-01 09:00:00', 'Asia/Tokyo'));
        $this->f = new ReservationFixture();
        $this->f->users->users[20] = FakeUserRepository::child();
        $this->f->users->users[21] = FakeUserRepository::child();
        $this->f->users->users[30] = FakeUserRepository::staff(UserRole::ADMIN);
        $this->f->rooms->members   = array_merge($this->f->rooms->members, ['20:1', '21:1', '30:1']);
    }

    protected function tearDown(): void
    {
        Chronos::setTestNow(null);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $dayUsers
     * @param array<string, string> $snapshots
     * @return array<string, mixed>
     */
    private function edit(array $dayUsers, int $login = 10, bool $isAdmin = false, int $level = 0, array $snapshots = [], int $roomId = 1): array
    {
        return $this->f->bulkChangeEdit()->execute(new BulkChangeEditReservationsInput($dayUsers, $roomId, '職員', $snapshots, $login, $isAdmin, $level));
    }

    public function testInputAndLoginUserAreRequired(): void
    {
        $this->assertSame('部屋または予約内容が指定されていません。', $this->edit([])['message']);
        $this->assertSame('ログインユーザーを特定できないため処理を中断しました。', $this->edit([self::NEAR => []], 0)['message']);
    }

    public function testAdminCannotWriteToDeletedRoom(): void
    {
        $this->f->rooms->deletedRooms = [1];

        $this->assertSame('選択された部屋情報が不正です。画面を再読み込みして再実行してください。', $this->edit([self::NEAR => ['20' => ['1' => '1']]], 30, true)['message']);
    }

    public function testLastMinuteChange_writesOnlyChangeFlag_andNewRowHasNoOriginalPlan(): void
    {
        $this->f->reservations->record(20, self::NEAR, 1, 1);

        $result = $this->edit([self::NEAR => ['20' => ['1' => '0', '3' => '1']]]);

        $this->assertSame(['ok' => true, 'updated' => 1, 'created' => 1, 'skipped' => []], $result);
        $this->assertSame(['changeFlag' => 0, 'eatFlag' => null], array_intersect_key($this->f->reservations->updates[0], ['changeFlag' => 0, 'eatFlag' => 0]));
        $this->assertSame([0, 1], [$this->f->reservations->added[0]->eatFlag, $this->f->reservations->added[0]->changeFlag]);
        $this->assertSame([['date' => self::NEAR, 'userIds' => [20]]], $this->f->cache->forgotten);
    }

    public function testFarDateChange_alsoWritesOriginalPlan(): void
    {
        $this->f->reservations->record(20, self::FAR, 1, 1);

        $this->edit([self::FAR => ['20' => ['1' => '0']]]);

        $this->assertSame(['changeFlag' => 0, 'eatFlag' => 0], array_intersect_key($this->f->reservations->updates[0], ['changeFlag' => 0, 'eatFlag' => 0]));
    }

    public function testUnchangedRowsAreNotWritten(): void
    {
        $this->f->reservations->record(20, self::NEAR, 1, 1);

        $result = $this->edit([self::NEAR => ['20' => ['1' => '1', '2' => '0']]]);

        $this->assertSame(['ok' => true, 'updated' => 0, 'created' => 0, 'skipped' => []], $result);
    }

    public function testStaffLastMinuteCancel_isSkippedWithReason(): void
    {
        $this->f->reservations->record(10, self::NEAR, 1, 3);

        $result = $this->edit([self::NEAR => ['10' => ['3' => '0']]]);

        $this->assertTrue($result['ok']);
        $this->assertSame(['2026-11-05 の利用者ID 10 は職員のため、直前編集でのキャンセルはできません。'], $result['skipped']);
        $this->assertSame([], $this->f->reservations->updates);
    }

    public function testStaffLastMinuteLunchToBentoSwap_isRejectedAndRolledBack(): void
    {
        $this->f->reservations->record(20, self::NEAR, 1, 1);
        $this->f->reservations->record(10, self::NEAR, 1, 2);

        $result = $this->edit([self::NEAR => ['20' => ['1' => '0'], '10' => ['2' => '0', '4' => '1']]]);

        $this->assertFalse($result['ok']);
        $this->assertStringContainsString('利用者ID 10 は職員のため、直前期間はお昼とお弁当を入れ替えられません', $result['message']);
        $this->assertSame(1, $this->f->reservations->find(20, self::NEAR, 1, 1)?->changeFlag, '先に書いた取消も取り消される');
        $this->assertSame([], $this->f->cache->forgotten);
    }

    public function testPastDate_isSkippedForNonAdmin_butAdminCanFix(): void
    {
        $this->f->reservations->record(20, self::PAST, 1, 1);

        $staff = $this->edit([self::PAST => ['20' => ['1' => '0']]]);
        $this->assertSame(['2026-10-30 は過去日のため、利用者ID 20 の予約は変更できません。'], $staff['skipped']);
        $this->assertSame([], $this->f->reservations->updates);

        $admin = $this->edit([self::PAST => ['20' => ['1' => '0']]], 30, true);
        $this->assertSame(1, $admin['updated']);
    }

    public function testChildCannotEditOthers_andEarlierWritesAreRolledBack(): void
    {
        $this->f->reservations->record(20, self::FAR, 1, 1, 0, 0);

        $result = $this->edit([self::FAR => ['20' => ['1' => '1'], '21' => ['1' => '1']]], 20, false, 1);

        $this->assertSame(['ok' => false, 'message' => '他ユーザーの予約を更新する権限がありません。'], $result);
        $this->assertSame(0, $this->f->reservations->find(20, self::FAR, 1, 1)?->eatFlag);
        $this->assertSame([], $this->f->reservations->added);
    }

    public function testOptimisticConflict(): void
    {
        $this->f->reservations->record(20, self::NEAR, 1, 1);
        $this->f->reservations->onUpdate = static fn(): bool => false;

        $this->assertSame(['ok' => false, 'message' => '他の操作と競合しました。画面を再読み込みして再実行してください。'], $this->edit([self::NEAR => ['20' => ['1' => '0']]]));
    }

    public function testUnexpectedError_isGenericMessage(): void
    {
        $this->f->reservations->record(20, self::NEAR, 1, 1);
        $this->f->reservations->onUpdate = static function (): bool {
            throw new \LogicException('boom');
        };

        $this->assertSame(['ok' => false, 'message' => '直前編集の保存中にエラーが発生しました。'], $this->edit([self::NEAR => ['20' => ['1' => '0']]]));
    }

    public function testSnapshotConflict(): void
    {
        $this->f->reservations->latestUpdates['1:' . self::NEAR] = '2026-11-01 10:00:00';

        $result = $this->edit([self::NEAR => ['20' => ['1' => '1']]], 10, false, 0, [self::NEAR => '2026-11-01 09:00:00']);

        $this->assertSame(self::NEAR, $result['conflict_date']);
        $this->assertSame(1, $this->f->reservations->rollbacks);
    }
}
