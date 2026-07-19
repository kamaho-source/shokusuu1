<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\UserRoomAssignmentService;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * UserRoomAssignmentService テスト。
 *
 * assign の正常割り当て・既存グループ無効化・エラーハンドリングを検証する。
 *
 * Note: ユーザー4・5 はフィクスチャでグループ未割り当てのため新規割り当てテストに使用する。
 *       ユーザー1〜3 はすでに room 1 に割り当て済みのため再割り当てはPK重複を招く。
 */
class UserRoomAssignmentServiceTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.MUserInfo',
    ];

    private UserRoomAssignmentService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new UserRoomAssignmentService();
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

    /** フィクスチャ上で room 1 の名前を返す */
    private function roomOneName(): string
    {
        return TableRegistry::getTableLocator()->get('MRoomInfo')
            ->find()
            ->where(['i_id_room' => 1])
            ->enableHydration(false)
            ->first()['c_room_name'] ?? 'テナント1の部屋';
    }

    // ----------------------------------------------------------------
    // 正常系 — user 4（グループ未割り当て）を使用
    // ----------------------------------------------------------------

    public function testAssignCreatesNewGroupForExistingRoom(): void
    {
        $result = $this->service->assign(4, [$this->roomOneName()], 'test_actor');

        $this->assertSame(1, $result['created']);
        $this->assertEmpty($result['errors']);
    }

    public function testAssignDeactivatesOldGroups(): void
    {
        // まず user 4 を room 1 に割り当て
        $this->service->assign(4, [$this->roomOneName()], 'setup_actor');

        // 次に user 4 を別ユーザー（user 5）として扱いたいが、
        // ここでは user 4 に対して空配列を渡して既存グループを無効化する
        $this->service->assign(4, [], 'test_actor');

        $active = $this->findActiveGroups(4);
        $this->assertCount(0, $active);
    }

    public function testAssignReturnsEmptyErrorsOnSuccess(): void
    {
        $result = $this->service->assign(5, [$this->roomOneName()], 'test_actor');

        $this->assertEmpty($result['errors']);
    }

    public function testAssignLimitsToTwoRooms(): void
    {
        $roomName = $this->roomOneName();

        $result = $this->service->assign(
            5,
            [$roomName, $roomName, $roomName],
            'test_actor'
        );

        $this->assertLessThanOrEqual(2, $result['created'] + count($result['errors']));
    }

    // ----------------------------------------------------------------
    // 異常系
    // ----------------------------------------------------------------

    public function testAssignReturnsErrorForUnknownRoom(): void
    {
        $result = $this->service->assign(4, ['存在しない部屋'], 'test_actor');

        $this->assertSame(0, $result['created']);
        $this->assertCount(1, $result['errors']);
        $this->assertStringContainsString('存在しない部屋', $result['errors'][0]);
    }

    public function testAssignWithEmptyRoomNamesDeactivatesOldGroups(): void
    {
        $this->service->assign(1, [], 'test_actor');

        $active = $this->findActiveGroups(1);
        $this->assertCount(0, $active);
    }
}
