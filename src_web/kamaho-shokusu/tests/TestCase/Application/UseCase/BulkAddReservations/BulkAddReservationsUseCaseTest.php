<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\BulkAddReservations;

use App\Application\UseCase\BulkAddReservations\BulkAddReservationsInput;
use App\Domain\Exception\ConflictException;
use App\Domain\Exception\ReservationValidationException;
use App\Test\TestCase\Application\Fake\FakeUserRepository;
use App\Test\TestCase\Application\Fake\ReservationFixture;
use Cake\Chronos\Chronos;
use PHPUnit\Framework\TestCase;

/**
 * BulkAddReservationsUseCase のテスト（Repository は Fake。DB を使わない）。
 *
 * 今日は 2026-11-01 に固定する（通常予約は 11/16 以降）。利用者 10 は職員（部屋 1・7・8）、20・21 は児童（部屋 1）。
 */
class BulkAddReservationsUseCaseTest extends TestCase
{
    private const FAR   = '2026-11-20';
    private const FAR2  = '2026-11-21';
    private const TODAY = '2026-11-01';

    private ReservationFixture $f;

    protected function setUp(): void
    {
        parent::setUp();
        Chronos::setTestNow(new Chronos('2026-11-01 09:00:00', 'Asia/Tokyo'));
        $this->f = new ReservationFixture();
        $this->f->users->users[20] = FakeUserRepository::child();
        $this->f->users->users[21] = FakeUserRepository::child();
        $this->f->users->names     = [20 => '児童A', 21 => '児童B'];
        $this->f->rooms->members   = array_merge($this->f->rooms->members, ['20:1', '21:1']);
        $this->f->rooms->names     = [1 => '部屋1', 7 => '部屋7'];
    }

    protected function tearDown(): void
    {
        Chronos::setTestNow(null);
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $data
     * @return array<string, mixed>
     */
    private function add(array $data, int $userId = 10, int $level = 0, bool $isAdmin = false): array
    {
        return $this->f->bulkAdd()->execute(new BulkAddReservationsInput($data, $userId, '職員', $isAdmin, $level));
    }

    /**
     * @param array<int|string, mixed> $meals 食事区分 => [部屋ID => 0/1]
     * @param list<string> $dates
     * @return array<string, mixed>
     */
    private function personal(array $meals, array $dates = [self::FAR]): array
    {
        return $this->add(['reservation_type' => 'personal', 'dates' => array_fill_keys($dates, '1'), 'meals' => $meals]);
    }

    /**
     * @param array<string, mixed> $dayUsers
     * @return array<string, mixed>
     */
    private function group(array $dayUsers, array $snapshots = [], int $userId = 10, int $level = 0): array
    {
        return $this->add(['reservation_type' => 'group', 'i_id_room' => '1', 'day_users' => $dayUsers, 'reservation_snapshot' => $snapshots], $userId, $level);
    }

    public function testTypeAndLoginUserAreRequired(): void
    {
        $this->assertSame('予約タイプが選択されていません。', $this->add([])['message']);
        $this->assertSame('ログインユーザーを特定できないため処理を中断しました。', $this->add(['reservation_type' => 'group'], 0)['message']);
        $this->assertSame('無効な予約タイプが選択されました。', $this->add(['reservation_type' => 'weird'])['message']);
    }

    public function testPersonal_requiresDateAndMeal(): void
    {
        $this->assertSame('日付が選択されていません。', $this->add(['reservation_type' => 'personal', 'dates' => [self::FAR => '0'], 'meals' => [1 => [1 => '1']]])['message']);
        $this->assertSame('食事が選択されていません。', $this->personal([1 => [1 => '0']])['message']);
    }

    public function testPersonal_roomMustBeOwnActiveRoom(): void
    {
        $this->f->rooms->members = array_diff($this->f->rooms->members, ['10:7']);

        $result = $this->personal([1 => [7 => '1']]);

        $this->assertSame(['ok' => false, 'message' => '所属していない部屋には登録できません。'], $result);
    }

    public function testPersonal_deletedRoomIsRejected(): void
    {
        $this->f->rooms->deletedRooms = [7];

        $this->assertSame('選択された部屋情報が不正です。画面を再読み込みして再実行してください。', $this->personal([1 => [7 => '1']])['message']);
    }

    public function testPersonal_leadTimeAndPastDates(): void
    {
        $this->assertStringContainsString('15日目以降', $this->personal([1 => [1 => '1']], ['2026-11-10'])['message']);
        $this->assertSame('過去日は選べません。', $this->personal([1 => [1 => '1']], ['2026-10-30'])['message']);
        $this->assertSame('無効な日付が含まれています。', $this->personal([1 => [1 => '1']], ['2026/11/20'])['message']);
        $this->assertSame([], $this->f->reservations->added);
    }

    public function testPersonal_existingActiveIsSkipped_restIsCreated(): void
    {
        $this->f->reservations->record(10, self::FAR, 1, 1);

        $result = $this->personal([1 => [1 => '1'], 3 => [1 => '1']], [self::FAR, self::FAR2]);

        $this->assertTrue($result['ok']);
        $this->assertStringContainsString('日付 2026-11-20（部屋1）の"朝"は 職員 の予約が既に存在していたためスキップしました。', $result['message']);
        $this->assertCount(3, $this->f->reservations->added);
        $this->assertSame([1, 1], [$this->f->reservations->added[0]->eatFlag, $this->f->reservations->added[0]->changeFlag]);
    }

    public function testPersonal_inactiveRowIsReactivated(): void
    {
        $this->f->reservations->record(10, self::FAR, 1, 1, 0, 0);

        $this->personal([1 => [1 => '1']]);

        $this->assertSame([], $this->f->reservations->added);
        $this->assertSame(['changeFlag' => 1, 'eatFlag' => 1], array_intersect_key($this->f->reservations->updates[0], ['changeFlag' => 0, 'eatFlag' => 0]));
    }

    public function testPersonal_lunchSwapsOutBentoOnlyWhenBentoIsExplicitlyUnchecked(): void
    {
        $this->f->reservations->record(10, self::FAR, 7, 4);

        $result = $this->personal([2 => [1 => '1'], 4 => [1 => '0', 7 => '0']]);

        $this->assertStringContainsString('日付 2026-11-20 は弁当を取り消して昼食にしました。', $result['message']);
        $this->assertSame(0, $this->f->reservations->find(10, self::FAR, 7, 4)?->eatFlag);
        $this->assertSame(2, $this->f->reservations->added[0]->mealType);
    }

    public function testPersonal_lunchWithoutUncheckingBento_isRejectedOnSave(): void
    {
        $this->f->reservations->record(10, self::FAR, 7, 4);
        $updateCalls = 0;
        $this->f->reservations->onUpdate = static function () use (&$updateCalls): bool {
            $updateCalls++;

            return true;
        };
        $this->f->reservations->onAdd = static function (): void {
            throw new ReservationValidationException('昼食と弁当は同時に予約できません。どちらか一方にしてください。', [], '{}');
        };

        $result = $this->personal([2 => [1 => '1']]);

        $this->assertSame(['ok' => false, 'message' => '昼食と弁当は同時に予約できません。どちらか一方にしてください。'], $result);
        $this->assertSame(0, $updateCalls, '弁当は黙って取り消さない（取り消してから保存で失敗する、でもない）');
    }

    public function testGroup_staffRegistersAndCancelsChildren_andForgetsCaches(): void
    {
        $this->f->reservations->record(21, self::FAR, 1, 3);

        $result = $this->group([self::FAR => ['20' => ['1' => '1'], '21' => ['3' => '0']]]);

        $this->assertSame('すべての予約が正常に登録されました。', $result['message']);
        $this->assertSame(20, $this->f->reservations->added[0]->userId);
        $this->assertSame(0, $this->f->reservations->find(21, self::FAR, 1, 3)?->eatFlag);
        $this->assertSame([['date' => self::FAR, 'userIds' => [20, 21]]], $this->f->cache->forgotten);
        $this->assertSame(1, $this->f->cache->versionBumps);
    }

    public function testGroup_cancelOnlyTouchesTheAuthorizedRoom(): void
    {
        $this->f->reservations->record(20, self::FAR, 7, 3);

        $this->group([self::FAR => ['20' => ['3' => '0']]]);

        $this->assertSame([], $this->f->reservations->updates);
    }

    public function testGroup_childCannotRegisterOthers_andNothingIsWritten(): void
    {
        $result = $this->group([self::FAR => ['20' => ['1' => '1'], '21' => ['1' => '1']]], [], 20, 1);

        $this->assertSame(['ok' => false, 'message' => '他ユーザーの予約を更新する権限がありません。'], $result);
        $this->assertSame([], $this->f->reservations->added);
    }

    public function testGroup_actorOutsideRoomIsRejectedBeforeAnything(): void
    {
        $this->f->rooms->members = array_diff($this->f->rooms->members, ['10:1']);

        $this->assertSame('所属していない部屋には登録できません。', $this->group([self::FAR => ['20' => ['1' => '1']]])['message']);
    }

    public function testGroup_snapshotConflictReturnsDate(): void
    {
        $this->f->reservations->latestUpdates['1:' . self::FAR] = '2026-11-01 10:00:00';

        $result = $this->group([self::FAR => ['20' => ['1' => '1']]], [self::FAR => '2026-11-01 09:00:00']);

        $this->assertSame(self::FAR, $result['conflict_date']);
        $this->assertSame([], $this->f->reservations->added);
    }

    public function testGroup_optimisticConflictRollsBack(): void
    {
        $this->f->reservations->record(21, self::FAR, 1, 3);
        $this->f->reservations->onUpdate = static fn(): bool => false;

        $result = $this->group([self::FAR => ['20' => ['1' => '1'], '21' => ['3' => '0']]]);

        $this->assertSame(['ok' => false, 'message' => '他の操作と競合しました。画面を再読み込みして再実行してください。'], $result);
        $this->assertSame([], $this->f->reservations->added);
        $this->assertSame([], $this->f->cache->forgotten);
    }

    public function testGroup_approvedRowReturnsReason(): void
    {
        $this->f->reservations->record(21, self::FAR, 1, 3);
        $this->f->reservations->onUpdate = static function (): bool {
            throw new ConflictException('承認済みの予約は変更できません。');
        };

        $this->assertSame('承認済みの予約は変更できません。', $this->group([self::FAR => ['21' => ['3' => '0']]])['message']);
    }

    public function testGroupUsersForm_todayForgetsOnlyTodayReportWithoutVersionBump(): void
    {
        $result = $this->add([
            'reservation_type' => 'group',
            'i_id_room' => '1',
            'dates' => [self::TODAY => '1'],
            'users' => ['20' => ['morning' => '1']],
        ]);

        $this->assertTrue($result['ok']);
        $this->assertSame([['date' => self::TODAY, 'userIds' => [20]]], $this->f->cache->forgotten);
        $this->assertSame(0, $this->f->cache->versionBumps);
    }
}
