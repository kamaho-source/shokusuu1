<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\RestoreUser;

use App\Application\Port\AuditActor;
use App\Application\UseCase\RestoreUser\RestoreUserInput;
use App\Application\UseCase\RestoreUser\RestoreUserUseCase;
use App\Domain\Entity\UserAccount;
use App\Test\TestCase\Application\Fake\FakeUserAccountRepository;
use App\Test\TestCase\Application\Fake\SpyAuditLog;
use App\Test\TestCase\Application\Fake\SpyTransaction;
use PHPUnit\Framework\TestCase;

/**
 * RestoreUserUseCase のテスト（Repository・監査ログ・トランザクションは Fake）。
 */
class RestoreUserUseCaseTest extends TestCase
{
    private FakeUserAccountRepository $users;
    private SpyTransaction $transaction;
    private SpyAuditLog $audit;
    private RestoreUserUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new FakeUserAccountRepository();
        $this->users->users[20] = new UserAccount(20, '児童さん', 0, 0, 1);
        $this->transaction = new SpyTransaction();
        $this->audit       = new SpyAuditLog();
        $this->useCase     = new RestoreUserUseCase($this->users, $this->transaction, $this->audit);
    }

    private function restore(): int
    {
        return $this->useCase->execute(new RestoreUserInput(20, new AuditActor('管理者', 1, 'admin', '')))->restoredRoomCount;
    }

    public function testRestore_rejoinsOnlyRoomsLeftByDeletionInOneTransaction(): void
    {
        $this->users->leftByDeletion[20] = [1, 3];

        $this->assertSame(2, $this->restore());

        $this->assertFalse($this->users->users[20]->isDeleted());
        $this->assertSame(['rejoin:20:1,3'], $this->users->roomOps, '削除で外した部屋だけ戻す');
        $this->assertSame(['begin', 'commit'], $this->transaction->calls);
        $this->assertSame(['target_user_name' => '児童さん', 'restored_room_ids' => [1, 3]], $this->audit->records[0]['detail']);
        $this->assertTrue($this->audit->records[0]['succeeded']);
    }

    public function testRestore_withUnknownRooms_restoresWithoutRoomsAndTellsSo(): void
    {
        $this->assertSame(0, $this->restore(), '所属なしで復元したことを呼び出し側に返す');
        $this->assertFalse($this->users->users[20]->isDeleted());
        $this->assertSame(['rejoin:20:'], $this->users->roomOps);
    }

    /**
     * @return array<string, array{string, string}> [失敗させる処理, 監査ログに残る理由]
     */
    public static function failures(): array
    {
        return [
            '利用者の保存' => ['user', 'ユーザー情報の更新に失敗しました。'],
            '所属の保存'   => ['rooms', 'グループ情報の更新に失敗しました。'],
        ];
    }

    /**
     * @dataProvider failures
     */
    public function testFailure_rollsBackRecordsReasonAndThrows(string $fail, string $reason): void
    {
        $fail === 'user' ? $this->users->failSave = true : $this->users->failRooms = true;

        try {
            $this->restore();
            $this->fail('例外が出ていない');
        } catch (\RuntimeException $e) {
            $this->assertSame($reason, $e->getMessage());
        }
        $this->assertSame(['begin', 'rollback'], $this->transaction->calls);
        $this->assertFalse($this->audit->records[0]['succeeded']);
        $this->assertSame(['target_user_name' => '児童さん', 'error' => $reason], $this->audit->records[0]['detail']);
    }
}
