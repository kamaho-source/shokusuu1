<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\RoomService;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * RoomService テスト。
 *
 * nextDisplayNo・getUsersForRoom・softDelete の挙動を検証する。
 */
class RoomServiceTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
    ];

    private RoomService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new RoomService();
    }

    // ----------------------------------------------------------------
    // nextDisplayNo — DB使用
    // ----------------------------------------------------------------

    public function testNextDisplayNo_returnsInt(): void
    {
        $result = $this->service->nextDisplayNo();
        $this->assertIsInt($result);
    }

    public function testNextDisplayNo_greaterThanZero(): void
    {
        $result = $this->service->nextDisplayNo();
        $this->assertGreaterThan(0, $result);
    }

    public function testNextDisplayNo_greaterThanCurrentMax(): void
    {
        $roomTable = TableRegistry::getTableLocator()->get('MRoomInfo');
        $maxRow = $roomTable->find()
            ->select(['max_no' => 'MAX(i_disp_no)'])
            ->first();
        $currentMax = (int)($maxRow->max_no ?? 0);

        $result = $this->service->nextDisplayNo();

        $this->assertSame($currentMax + 1, $result);
    }

    // ----------------------------------------------------------------
    // getUsersForRoom — DB使用
    // ----------------------------------------------------------------

    public function testGetUsersForRoom_roomWithNoUsers_returnsEmpty(): void
    {
        // m_user_group が空のエンティティを作成するため、実エンティティをロードして上書きする
        $roomTable = TableRegistry::getTableLocator()->get('MRoomInfo');
        $roomInfo  = $roomTable->get(1, contain: ['MUserGroup']);

        // m_user_group を空に設定
        $roomInfo->m_user_group = [];

        $result = $this->service->getUsersForRoom($roomInfo);

        $this->assertSame([], $result);
    }

    public function testGetUsersForRoom_roomWithUsers_returnsArray(): void
    {
        $roomTable = TableRegistry::getTableLocator()->get('MRoomInfo');
        $roomInfo  = $roomTable->get(1, contain: ['MUserGroup']);

        $result = $this->service->getUsersForRoom($roomInfo);

        $this->assertIsArray($result);

        $expectedUserIds = array_map(
            static fn ($group) => (int)$group->i_id_user,
            $roomInfo->m_user_group
        );
        $actualUserIds = array_map(
            static fn ($user) => (int)$user->i_id_user,
            $result
        );
        sort($expectedUserIds);
        sort($actualUserIds);
        $this->assertNotEmpty($expectedUserIds);
        $this->assertSame($expectedUserIds, $actualUserIds);
    }

    // ----------------------------------------------------------------
    // softDelete — DB使用
    // ----------------------------------------------------------------

    public function testSoftDelete_existingRoom_returnsTrue(): void
    {
        $roomTable = TableRegistry::getTableLocator()->get('MRoomInfo');
        $room = $roomTable->get(1);

        $result = $this->service->softDelete($room, 'test_admin');

        $this->assertTrue($result);
    }

    public function testSoftDelete_setsDelFlag(): void
    {
        $roomTable = TableRegistry::getTableLocator()->get('MRoomInfo');
        $room = $roomTable->get(1);

        $this->service->softDelete($room, 'test_admin');

        $updated = $roomTable->get(1);
        $this->assertSame(1, (int)$updated->i_del_flg);
    }

    public function testSoftDelete_setsUpdateUser(): void
    {
        $roomTable = TableRegistry::getTableLocator()->get('MRoomInfo');
        $room = $roomTable->get(1);

        $this->service->softDelete($room, 'test_admin');

        $updated = $roomTable->get(1);
        $this->assertSame('test_admin', $updated->c_update_user);
    }

    // ----------------------------------------------------------------
    // buildAffiliationLabel — DB不要
    // ----------------------------------------------------------------

    public function testBuildAffiliationLabel_empty_returnsMishozoku(): void
    {
        $this->assertSame('未所属', $this->service->buildAffiliationLabel([], 3));
    }

    public function testBuildAffiliationLabel_partial_returnsCommaSeparated(): void
    {
        $result = $this->service->buildAffiliationLabel(['部屋A', '部屋B'], 3);
        $this->assertSame('部屋A, 部屋B', $result);
    }

    public function testBuildAffiliationLabel_allRooms_returnsZenbuya(): void
    {
        $result = $this->service->buildAffiliationLabel(['部屋A', '部屋B', '部屋C'], 3);
        $this->assertSame('全部屋所属', $result);
    }

    public function testBuildAffiliationLabel_duplicateNamesAreUniqued(): void
    {
        // 同一部屋の重複行があっても全部屋所属と誤判定しない
        $result = $this->service->buildAffiliationLabel(['部屋A', '部屋A', '部屋B'], 3);
        $this->assertSame('部屋A, 部屋B', $result);
    }

    public function testBuildAffiliationLabel_zeroTotalRooms_neverReturnsZenbuya(): void
    {
        $result = $this->service->buildAffiliationLabel(['部屋A'], 0);
        $this->assertSame('部屋A', $result);
    }

    // ----------------------------------------------------------------
    // countActiveRooms — DB使用
    // ----------------------------------------------------------------

    public function testCountActiveRooms_excludesDeletedRooms(): void
    {
        $roomTable = TableRegistry::getTableLocator()->get('MRoomInfo');
        $before = $this->service->countActiveRooms();

        $room = $roomTable->get(1);
        $this->service->softDelete($room, 'test_admin');

        $this->assertSame($before - 1, $this->service->countActiveRooms());
    }
}
