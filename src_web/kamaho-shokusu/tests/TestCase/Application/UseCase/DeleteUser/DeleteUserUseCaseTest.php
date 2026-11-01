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
use Cake\Chronos\Chronos;
use Cake\Chronos\ClockFactory;
use PHPUnit\Framework\TestCase;

/**
 * DeleteUserUseCase のテスト（Repository・監査ログは Fake）。今日は 2026-11-01 に固定する。
 */
class DeleteUserUseCaseTest extends TestCase
{
    private FakeUserAccountRepository $users;
    private FakeReservationRepository $reservations;
    private SpyAuditLog $audit;
    private DeleteUserUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();
        Chronos::setTestNow(new Chronos('2026-11-01 09:00:00', 'Asia/Tokyo'));
        $this->users = new FakeUserAccountRepository();
        $this->users->users[20] = new UserAccount(20, '児童さん', 0, 0, 0);
        $this->reservations = new FakeReservationRepository();
        $this->audit        = new SpyAuditLog();
        $this->useCase      = new DeleteUserUseCase($this->users, $this->reservations, $this->audit, new ClockFactory('Asia/Tokyo'));
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

    public function testDelete_leavesRoomsMarksDeletedAndRemovesUnapprovedFromTomorrow(): void
    {
        $this->assertTrue($this->delete(20));

        $this->assertSame(['leave:20'], $this->users->roomOps);
        $this->assertTrue($this->users->users[20]->isDeleted());
        $this->assertSame([[20, '2026-11-01']], $this->reservations->deletedAfter, '今日より後（翌日以降）の未承認を消す');
        $log = $this->audit->records[0];
        $this->assertSame(['user_delete', '20', true, null], [$log['action'], $log['targetId'], $log['succeeded'], $log['failureReason']]);
        $this->assertSame(['target_user_name' => '児童さん'], $log['detail']);
    }

    public function testSaveFailure_keepsReservationsAndRecordsFailure(): void
    {
        $this->users->failSave = true;

        $this->assertFalse($this->delete(20));

        $this->assertSame([], $this->reservations->deletedAfter, '削除済みにできないのに予約が消えている');
        $this->assertFalse($this->audit->records[0]['succeeded']);
        $this->assertSame('ユーザーの削除（論理削除）の保存に失敗しました', $this->audit->records[0]['failureReason']);
    }

    public function testMissingUser_isNotFoundAndNothingHappens(): void
    {
        try {
            $this->delete(999);
            $this->fail('例外が出ていない');
        } catch (NotFoundException) {
        }
        $this->assertSame([], $this->users->roomOps);
        $this->assertSame([], $this->audit->records);
    }
}
