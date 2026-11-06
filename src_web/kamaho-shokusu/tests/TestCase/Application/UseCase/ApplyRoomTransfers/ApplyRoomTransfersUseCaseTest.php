<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\ApplyRoomTransfers;

use App\Application\UseCase\ApplyRoomTransfers\ApplyRoomTransfersInput;
use App\Application\UseCase\ApplyRoomTransfers\ApplyRoomTransfersUseCase;
use App\Domain\Entity\RoomTransferSchedule;
use App\Domain\Entity\UserAccount;
use App\Test\TestCase\Application\Fake\FakeReservationRepository;
use App\Test\TestCase\Application\Fake\FakeRoomTransferScheduleRepository;
use App\Test\TestCase\Application\Fake\FakeUserAccountRepository;
use App\Test\TestCase\Application\Fake\SpyReservationCache;
use App\Test\TestCase\Application\Fake\SpyTransaction;
use Cake\Chronos\Chronos;
use Cake\Chronos\ClockFactory;
use PHPUnit\Framework\TestCase;

/**
 * ApplyRoomTransfersUseCase のテスト（Repository・トランザクション・キャッシュは Fake）。今は 2026-11-02 09:00。
 */
class ApplyRoomTransfersUseCaseTest extends TestCase
{
    private FakeRoomTransferScheduleRepository $schedules;
    private FakeUserAccountRepository $users;
    private FakeReservationRepository $reservations;
    private SpyTransaction $transaction;
    private SpyReservationCache $cache;

    protected function setUp(): void
    {
        parent::setUp();
        Chronos::setTestNow(new Chronos('2026-11-02 09:00:00', 'Asia/Tokyo'));
        $this->schedules = new FakeRoomTransferScheduleRepository();
        $this->users = new FakeUserAccountRepository();
        $this->users->users[20] = new UserAccount(20, '児童さん', 0, 0, 0);
        $this->users->users[21] = new UserAccount(21, '削除済みさん', 0, 0, 1);
        $this->reservations = new FakeReservationRepository();
        $this->transaction = new SpyTransaction();
        $this->cache = new SpyReservationCache();
    }

    protected function tearDown(): void
    {
        Chronos::setTestNow(null);
        parent::tearDown();
    }

    private function useCase(): ApplyRoomTransfersUseCase
    {
        return new ApplyRoomTransfersUseCase($this->schedules, $this->users, $this->reservations, $this->transaction, $this->cache, new ClockFactory('Asia/Tokyo'));
    }

    public function testApply_movesMembershipReservationsAndCounts_thenMarksApplied(): void
    {
        $this->schedules->schedules[1] = new RoomTransferSchedule(1, 20, 1, 2, '2026-11-10', RoomTransferSchedule::STATUS_PENDING);
        $this->schedules->schedules[2] = new RoomTransferSchedule(2, 20, 1, 2, '2026-12-01', RoomTransferSchedule::STATUS_PENDING);
        $this->reservations->record(20, '2026-11-09', 1, 1);
        $this->reservations->record(20, '2026-11-20', 1, 1);
        $this->reservations->record(20, '2026-11-20', 2, 2, 0, 0);

        $result = $this->useCase()->execute(new ApplyRoomTransfersInput('2026-11-10'));

        $this->assertSame(1, $result->applied, '有効開始日が基準日より後の予約は適用しない');
        $this->assertSame(['transfer:20:1->2'], $this->users->roomOps);
        $this->assertSame(['move:2026-11-20:1->2:create', 'count:2026-11-20:1:-1', 'count:2026-11-20:2:+1'], $this->reservations->transferOps, '有効開始日より前の予約は移さない');
        $this->assertSame([[1, RoomTransferSchedule::STATUS_APPLIED, 'batch']], $this->schedules->statusChanges);
        $this->assertSame(['begin', 'commit'], $this->transaction->calls);
        $this->assertSame([['date' => '2026-11-10', 'userId' => 20]], $this->cache->afterTransfer);
    }

    public function testNewAssignment_movesNoReservations(): void
    {
        $this->schedules->schedules[1] = new RoomTransferSchedule(1, 20, null, 2, '2026-11-02', RoomTransferSchedule::STATUS_PENDING);
        $this->reservations->record(20, '2026-11-20', 1, 1);

        $this->assertSame(1, $this->useCase()->execute(new ApplyRoomTransfersInput('2026-11-02'))->applied);
        $this->assertSame(['transfer:20:-->2'], $this->users->roomOps);
        $this->assertSame([], $this->reservations->transferOps);
    }

    public function testDeletedUser_isSkippedAndStaysPending(): void
    {
        $this->schedules->schedules[1] = new RoomTransferSchedule(1, 21, 1, 2, '2026-11-02', RoomTransferSchedule::STATUS_PENDING);

        $result = $this->useCase()->execute(new ApplyRoomTransfersInput('2026-11-02'));

        $this->assertSame(0, $result->applied);
        $this->assertSame([], $result->errors);
        $this->assertSame([], $this->transaction->calls);
        $this->assertSame([], $this->schedules->statusChanges);
    }

    public function testDryRun_rollsBackAndKeepsCache(): void
    {
        $this->schedules->schedules[1] = new RoomTransferSchedule(1, 20, 1, 2, '2026-11-02', RoomTransferSchedule::STATUS_PENDING);

        $this->assertSame(1, $this->useCase()->execute(new ApplyRoomTransfersInput('2026-11-02', dryRun: true))->applied);
        $this->assertSame(['begin', 'rollback'], $this->transaction->calls);
        $this->assertSame([], $this->cache->afterTransfer);
    }

    public function testFailure_rollsBackThatScheduleAndContinues(): void
    {
        $this->schedules->schedules[1] = new RoomTransferSchedule(1, 20, 1, 2, '2026-11-02', RoomTransferSchedule::STATUS_PENDING);
        $this->schedules->schedules[2] = new RoomTransferSchedule(2, 20, null, 3, '2026-11-02', RoomTransferSchedule::STATUS_PENDING);
        $this->reservations->record(20, '2026-11-20', 1, 1);
        $this->reservations->moveThrows = new \RuntimeException('接続断');

        $result = $this->useCase()->execute(new ApplyRoomTransfersInput('2026-11-02'));

        $this->assertSame(1, $result->applied);
        $this->assertSame(['スケジュールID=1: 接続断'], $result->errors);
        $this->assertSame(['begin', 'rollback', 'begin', 'commit'], $this->transaction->calls);
        $this->assertSame([[2, RoomTransferSchedule::STATUS_APPLIED, 'batch']], $this->schedules->statusChanges);
        $this->assertSame([['date' => '2026-11-02', 'userId' => 20]], $this->cache->afterTransfer, '取り消した予約のキャッシュは捨てない');
    }

    /** 登録済みの「異動元と異動先が同じ部屋」の異動は、予約を動かさずに理由を返す。ほかの異動は適用する（#945） */
    public function testSameRoom_isNotApplied_andOthersAre(): void
    {
        $this->schedules->schedules[1] = new RoomTransferSchedule(1, 20, 1, 1, '2026-11-02', RoomTransferSchedule::STATUS_PENDING);
        $this->schedules->schedules[2] = new RoomTransferSchedule(2, 20, null, 3, '2026-11-02', RoomTransferSchedule::STATUS_PENDING);
        $this->reservations->record(20, '2026-11-20', 1, 1);

        $result = $this->useCase()->execute(new ApplyRoomTransfersInput('2026-11-02'));

        $this->assertSame(1, $result->applied);
        $this->assertSame(['スケジュールID=1: 異動元と異動先が同じ部屋です。'], $result->errors);
        $this->assertSame(['transfer:20:-->3'], $this->users->roomOps, '同じ部屋の異動では所属を変えない');
        $this->assertSame([], $this->reservations->transferOps, '予約を動かさない');
        $this->assertSame([[2, RoomTransferSchedule::STATUS_APPLIED, 'batch']], $this->schedules->statusChanges);
        $this->assertSame(['begin', 'commit'], $this->transaction->calls, '同じ部屋の異動はトランザクションも開かない');
    }

    /** 適用できなかった異動は「適用失敗」にして理由を残す（毎晩同じ失敗を繰り返さない。#945） */
    public function testFailure_marksScheduleFailedWithReason(): void
    {
        $this->schedules->schedules[1] = new RoomTransferSchedule(1, 20, 1, 2, '2026-11-02', RoomTransferSchedule::STATUS_PENDING);
        $this->reservations->record(20, '2026-11-20', 1, 1);
        $this->reservations->moveThrows = new \App\Domain\Exception\ConflictException('昼と弁当が重なります。');

        $result = $this->useCase()->execute(new ApplyRoomTransfersInput('2026-11-02'));

        $this->assertSame(['スケジュールID=1: 昼と弁当が重なります。'], $result->errors, 'その日の実行はエラーとして知らせる');
        $this->assertSame([[1, '昼と弁当が重なります。']], $this->schedules->failures);
        $this->assertSame(['begin', 'rollback'], $this->transaction->calls, '異動そのものは取り消す');
    }

    public function testSameRoom_marksScheduleFailed(): void
    {
        $this->schedules->schedules[1] = new RoomTransferSchedule(1, 20, 1, 1, '2026-11-02', RoomTransferSchedule::STATUS_PENDING);

        $this->useCase()->execute(new ApplyRoomTransfersInput('2026-11-02'));

        $this->assertSame([[1, RoomTransferSchedule::SAME_ROOM_MESSAGE]], $this->schedules->failures);
    }

    /** ドライランでは、失敗しても状態を変えない */
    public function testDryRun_failureIsNotMarked(): void
    {
        $this->schedules->schedules[1] = new RoomTransferSchedule(1, 20, 1, 2, '2026-11-02', RoomTransferSchedule::STATUS_PENDING);
        $this->schedules->schedules[2] = new RoomTransferSchedule(2, 20, 1, 1, '2026-11-02', RoomTransferSchedule::STATUS_PENDING);
        $this->reservations->record(20, '2026-11-20', 1, 1);
        $this->reservations->moveThrows = new \RuntimeException('昼と弁当が重なります。');

        $result = $this->useCase()->execute(new ApplyRoomTransfersInput('2026-11-02', true));

        $this->assertCount(2, $result->errors);
        $this->assertSame([], $this->schedules->failures);
    }

    /** バッチの途中で管理者が取り消した異動は、適用も「適用失敗」もしない（取消を上書きしない） */
    public function testCancelledDuringRun_isNotAppliedNorMarkedFailed(): void
    {
        $this->schedules->schedules[1] = new RoomTransferSchedule(1, 20, 1, 2, '2026-11-02', RoomTransferSchedule::STATUS_PENDING);
        $this->schedules->currentStatus[1] = RoomTransferSchedule::STATUS_CANCELLED;

        $result = $this->useCase()->execute(new ApplyRoomTransfersInput('2026-11-02'));

        $this->assertSame(0, $result->applied);
        $this->assertSame(['スケジュールID=1: ほかの操作で予約の状態が変わったため、適用しませんでした。'], $result->errors);
        $this->assertSame(['begin', 'rollback'], $this->transaction->calls, '所属・予約の変更も取り消す');
        $this->assertSame([], $this->schedules->statusChanges);
        $this->assertSame([], $this->schedules->failures);
    }

    /** 適用を確定したあとのキャッシュの削除に失敗しても、適用済みのまま（「適用失敗」にしない） */
    public function testCacheFailureAfterCommit_keepsApplied(): void
    {
        $this->schedules->schedules[1] = new RoomTransferSchedule(1, 20, null, 2, '2026-11-02', RoomTransferSchedule::STATUS_PENDING);
        $this->cache->afterTransferThrows = new \RuntimeException('boom');

        $result = $this->useCase()->execute(new ApplyRoomTransfersInput('2026-11-02'));

        $this->assertSame(1, $result->applied);
        $this->assertSame(['スケジュールID=1: 適用しましたが、キャッシュを消せませんでした（boom）'], $result->errors);
        $this->assertSame([], $this->schedules->failures);
        $this->assertSame(['begin', 'commit'], $this->transaction->calls);
    }

    /** 予期しないエラー（DB など）は、画面に出す理由を決まった文にする（その日のエラーの文には詳細を残す） */
    public function testUnexpectedError_hidesDetailsFromScreen(): void
    {
        $this->schedules->schedules[1] = new RoomTransferSchedule(1, 20, 1, 2, '2026-11-02', RoomTransferSchedule::STATUS_PENDING);
        $this->reservations->record(20, '2026-11-20', 1, 1);
        $this->reservations->moveThrows = new \RuntimeException('SQLSTATE[HY000]: General error');

        $result = $this->useCase()->execute(new ApplyRoomTransfersInput('2026-11-02'));

        $this->assertSame(['スケジュールID=1: SQLSTATE[HY000]: General error'], $result->errors);
        $this->assertSame([[1, '予期しないエラーで適用できませんでした。バッチのログを確認してください。']], $this->schedules->failures);
    }
}
