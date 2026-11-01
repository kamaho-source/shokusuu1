<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\AssignUserRooms;

use App\Application\UseCase\AssignUserRooms\AssignUserRoomsInput;
use App\Application\UseCase\AssignUserRooms\AssignUserRoomsUseCase;
use App\Domain\Entity\UserAccount;
use App\Test\TestCase\Application\Fake\FakeRoomMembershipRepository;
use App\Test\TestCase\Application\Fake\FakeUserAccountRepository;
use App\Test\TestCase\Application\Fake\SpyTransaction;
use PHPUnit\Framework\TestCase;

/**
 * AssignUserRoomsUseCase のテスト（Repository・トランザクションは Fake）。
 */
class AssignUserRoomsUseCaseTest extends TestCase
{
    private FakeUserAccountRepository $users;
    private FakeRoomMembershipRepository $rooms;
    private SpyTransaction $transaction;
    private AssignUserRoomsUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new FakeUserAccountRepository();
        $this->users->users[20] = new UserAccount(20, '児童さん', 0, 0, 0);
        $this->users->users[21] = new UserAccount(21, '削除済みさん', 0, 0, 1);
        $this->rooms = new FakeRoomMembershipRepository();
        $this->rooms->roomIdsByName = ['ナザレ' => 1, 'ガリラヤ' => 2, 'ベツレヘム' => 3];
        $this->transaction = new SpyTransaction();
        $this->useCase     = new AssignUserRoomsUseCase($this->users, $this->rooms, $this->transaction);
    }

    public function testAssign_leavesOldRoomsThenJoinsUpToTwoRooms(): void
    {
        $result = $this->useCase->execute(new AssignUserRoomsInput(20, ['ナザレ', 'ガリラヤ', 'ベツレヘム'], 'API'));

        $this->assertSame(2, $result->created);
        $this->assertSame([], $result->errors);
        $this->assertSame(['leave:20', 'join:20:1', 'join:20:2'], $this->users->roomOps, '3つ目は使わない');
        $this->assertSame(['begin', 'commit'], $this->transaction->calls);
    }

    public function testUnknownRoomAndSaveFailure_areReportedAndTheRestContinues(): void
    {
        $result = $this->useCase->execute(new AssignUserRoomsInput(20, ['存在しない部屋', 'ナザレ'], 'API'));
        $this->assertSame(1, $result->created);
        $this->assertSame(["部屋名 '存在しない部屋' が見つかりません"], $result->errors);

        $this->users->failRooms = true;
        $result = $this->useCase->execute(new AssignUserRoomsInput(20, ['ナザレ'], 'API'));
        $this->assertSame(0, $result->created);
        $this->assertSame(["部屋 'ナザレ' の登録に失敗"], $result->errors);
    }

    public function testEmptyRoomNames_onlyLeavesOldRooms(): void
    {
        $this->assertSame(0, $this->useCase->execute(new AssignUserRoomsInput(20, [], 'API'))->created);
        $this->assertSame(['leave:20'], $this->users->roomOps);
    }

    public function testDbFailure_rollsBackAndRethrows(): void
    {
        $failing = new AssignUserRoomsUseCase($this->users, new class implements \App\Domain\Repository\RoomMembershipRepositoryInterface {
            public function isActiveMember(int $userId, int $roomId): bool { return true; }
            public function isRoomAvailable(int $roomId): bool { return true; }
            public function roomNames(array $roomIds): array { return []; }
            public function roomIdByName(string $roomName, ?array $onlyRoomIds = null): ?int { throw new \RuntimeException('DB down'); }
            public function activeRoomIds(int $userId): array { return []; }
            public function selectableRooms(?array $onlyRoomIds = null): array { return []; }
        }, $this->transaction);

        try {
            $failing->execute(new AssignUserRoomsInput(20, ['ナザレ'], 'API'));
            $this->fail('例外が出ていない');
        } catch (\RuntimeException $e) {
            $this->assertSame('DB down', $e->getMessage());
        }
        $this->assertSame(['begin', 'rollback'], $this->transaction->calls);
    }

    public function testIsActiveUser_excludesDeletedAndMissing(): void
    {
        $this->assertTrue($this->useCase->isActiveUser(20));
        $this->assertFalse($this->useCase->isActiveUser(21));
        $this->assertFalse($this->useCase->isActiveUser(999));
    }
}
