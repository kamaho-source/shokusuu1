<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\DeleteUser;

use App\Application\Port\AuditActor;
use App\Application\UseCase\DeleteUser\DeleteUserInput;
use App\Application\UseCase\DeleteUser\DeleteUserUseCase;
use App\Domain\Entity\UserAccount;
use App\Domain\Exception\NotFoundException;
use App\Test\TestCase\Application\Fake\FakeReservationRepository;
use App\Test\TestCase\Application\Fake\FakeUserAccountRepository;
use App\Test\TestCase\Application\Fake\SpyAuditLog;
use App\Test\TestCase\Application\Fake\SpyTransaction;
use Cake\Chronos\Chronos;
use Cake\Chronos\ClockFactory;
use PHPUnit\Framework\TestCase;

/**
 * DeleteUserUseCase のテスト（Repository・監査ログ・トランザクションは Fake）。今は 2026-11-01 09:00 に固定する。
 */
class DeleteUserUseCaseTest extends TestCase
{
    private FakeUserAccountRepository $users;
    private FakeReservationRepository $reservations;
    private SpyTransaction $transaction;
    private SpyAuditLog $audit;
    private DeleteUserUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();
        Chronos::setTestNow(new Chronos('2026-11-01 09:00:00', 'Asia/Tokyo'));
        $this->users = new FakeUserAccountRepository();
        $this->users->users[20] = new UserAccount(20, '児童さん', 0, 0, 0);
        $this->reservations = new FakeReservationRepository();
        $this->transaction  = new SpyTransaction();
        $this->audit        = new SpyAuditLog();
        $this->useCase      = new DeleteUserUseCase($this->users, $this->reservations, $this->transaction, $this->audit, new ClockFactory('Asia/Tokyo'));
    }

    protected function tearDown(): void
    {
        Chronos::setTestNow(null);
        parent::tearDown();
    }

    private function delete(int $userId): bool
    {
        return $this->useCase->execute(new DeleteUserInput($userId, new AuditActor('管理者', 1, 'admin', '10.0.0.1')))->deleted;
    }

    public function testDelete_inOneTransaction_marksRoomsAtTheSameTimeAndRemovesUnapprovedFromTomorrow(): void
    {
        $this->assertTrue($this->delete(20));

        $this->assertSame(['leaveForDeletion:20@2026-11-01 09:00:00'], $this->users->roomOps, '所属を「削除で外した」印つきで外す');
        $this->assertSame(['2026-11-01 09:00:00'], $this->users->deletedAt, '削除済みにする時刻を、所属を外した時刻とそろえる');
        $this->assertTrue($this->users->users[20]->isDeleted());
        $this->assertSame([[20, '2026-11-01']], $this->reservations->deletedAfter, '今日より後（翌日以降）の未承認を消す');
        $this->assertSame(['begin', 'commit'], $this->transaction->calls);
        $log = $this->audit->records[0];
        $this->assertSame(['user_delete', '20', true, null], [$log['action'], $log['targetId'], $log['succeeded'], $log['failureReason']]);
        $this->assertSame(['target_user_name' => '児童さん'], $log['detail']);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function saveFailures(): array
    {
        return ['所属の退出' => ['leave'], '削除済みにする' => ['user']];
    }

    /**
     * @dataProvider saveFailures
     */
    public function testSaveFailure_rollsBackEverythingAndKeepsReservations(string $fail): void
    {
        $fail === 'leave' ? $this->users->failLeaveForDeletion = true : $this->users->failSave = true;

        $this->assertFalse($this->delete(20));

        $this->assertSame(['begin', 'rollback'], $this->transaction->calls, '途中の変更を取り消す');
        $this->assertSame([], $this->reservations->deletedAfter, '削除できないのに予約が消えている');
        $this->assertFalse($this->audit->records[0]['succeeded']);
        $this->assertSame('ユーザーの削除（論理削除）の保存に失敗しました', $this->audit->records[0]['failureReason']);
    }

    public function testDbFailure_rollsBackRecordsAndRethrows(): void
    {
        $this->reservations->deleteUnapprovedFails = new \RuntimeException('DB down');

        try {
            $this->useCase->execute(new DeleteUserInput(20, new AuditActor('管理者', 1, 'admin', '')));
            $this->fail('例外が出ていない');
        } catch (\RuntimeException $e) {
            $this->assertSame('DB down', $e->getMessage());
        }
        $this->assertSame(['begin', 'rollback'], $this->transaction->calls);
        $this->assertFalse($this->audit->records[0]['succeeded']);
        $this->assertStringContainsString('DB down', (string)$this->audit->records[0]['failureReason']);
    }

    public function testMissingUser_isNotFoundAndNothingHappens(): void
    {
        $this->assertNull($this->useCase->target(999));
        try {
            $this->delete(999);
            $this->fail('例外が出ていない');
        } catch (NotFoundException) {
        }
        $this->assertSame([], $this->users->roomOps);
        $this->assertSame([], $this->transaction->calls);
        $this->assertSame([], $this->audit->records);
    }
}
