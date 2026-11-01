<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\AssignUserRooms;

use App\Application\UseCase\AssignUserRooms\AssignUserRoomsInput;
use App\Application\UseCase\AssignUserRooms\AssignUserRoomsUseCase;
use App\Infrastructure\Persistence\CakeTransactionManager;
use App\Infrastructure\Repository\CakeRoomMembershipRepository;
use App\Infrastructure\Repository\CakeUserAccountRepository;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * AssignUserRoomsUseCase を本物の Repository につないだテスト（旧 UserRoomAssignmentServiceTest を引き継ぐ）。
 *
 * assign の正常割り当て・既存グループ無効化・エラーハンドリングを検証する。
 */
class AssignUserRoomsIntegrationTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.MUserInfo',
    ];



    /**
     * AssignUserRoomsUseCase を本物の Repository につないで実行する（旧 UserRoomAssignmentService::assign と同じ戻り値）。
     *
     * @param list<string> $roomNames
     * @return array{created: int, errors: list<string>}
     */
    private function assign(int $userId, array $roomNames, string $actor): array
    {
        $users   = $this->getTableLocator()->get('MUserInfo');
        $useCase = new AssignUserRoomsUseCase(new CakeUserAccountRepository($users), new CakeRoomMembershipRepository(), new CakeTransactionManager($users->getConnection()));
        $result  = $useCase->execute(new AssignUserRoomsInput($userId, $roomNames, $actor));

        return ['created' => $result->created, 'errors' => $result->errors];
    }

    private function userGroupTable(): \Cake\ORM\Table
    {
        return TableRegistry::getTableLocator()->get('MUserGroup');
    }

    private function findActiveGroups(int $userId): array
    {
        return $this->userGroupTable()
            ->find()
            ->where(['i_id_user' => $userId, 'active_flag' => 0])
            ->enableHydration(false)
            ->toArray();
    }

    private function getExistingRoomName(int $userId = 1): string
    {
        return $this->userGroupTable()
            ->find()
            ->join([
                'r' => [
                    'table' => 'm_room_info',
                    'type' => 'INNER',
                    'conditions' => 'r.i_id_room = MUserGroup.i_id_room',
                ],
            ])
            ->select(['r.c_room_name'])
            ->where(['MUserGroup.i_id_user' => $userId])
            ->enableHydration(false)
            ->first()['c_room_name'] ?? 'Lorem ipsum dolor sit amet';
    }

    // ----------------------------------------------------------------
    // 正常系
    // ----------------------------------------------------------------

    public function testAssignCreatesNewGroupForExistingRoom(): void
    {
        $roomName = $this->userGroupTable()
            ->find()
            ->join([
                'r' => [
                    'table' => 'm_room_info',
                    'type' => 'INNER',
                    'conditions' => 'r.i_id_room = MUserGroup.i_id_room',
                ],
            ])
            ->select(['r.c_room_name'])
            ->where(['MUserGroup.i_id_user' => 1])
            ->enableHydration(false)
            ->first()['c_room_name'] ?? 'Lorem ipsum dolor sit amet';

        $result = $this->assign(1, [$roomName], 'test_actor');

        $this->assertSame(1, $result['created']);
        $this->assertEmpty($result['errors']);
    }

    public function testAssignDeactivatesOldGroups(): void
    {
        $roomName = $this->getExistingRoomName();

        $this->assign(1, [$roomName], 'test_actor');

        $active = $this->findActiveGroups(1);
        // 古いグループ(room_id=1)は inactive になり、新しいグループが1件だけ active
        $this->assertCount(1, $active);
    }

    public function testAssignReturnsEmptyErrorsOnSuccess(): void
    {
        $result = $this->assign(1, [$this->getExistingRoomName()], 'test_actor');

        $this->assertEmpty($result['errors']);
    }

    public function testAssignLimitsToTwoRooms(): void
    {
        $roomName = $this->getExistingRoomName();

        // 3件渡しても最大2件しか処理されない
        $result = $this->assign(
            1,
            [$roomName, $roomName, $roomName],
            'test_actor'
        );

        // 同じ部屋名が複数回指定されても最大2件
        $this->assertLessThanOrEqual(2, $result['created'] + count($result['errors']));
    }

    // ----------------------------------------------------------------
    // 異常系
    // ----------------------------------------------------------------

    public function testAssignReturnsErrorForUnknownRoom(): void
    {
        $result = $this->assign(1, ['存在しない部屋'], 'test_actor');

        $this->assertSame(0, $result['created']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('存在しない部屋', $result['errors'][0]);
    }

    public function testAssignWithEmptyRoomNamesDeactivatesOldGroups(): void
    {
        $this->assign(1, [], 'test_actor');

        // 部屋名が空なので既存グループが無効化されるだけ
        $active = $this->findActiveGroups(1);
        $this->assertCount(0, $active);
    }
}
