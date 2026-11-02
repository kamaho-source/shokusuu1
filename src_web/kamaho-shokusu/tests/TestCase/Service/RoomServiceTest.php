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

        // 削除済みユーザー(6)は除く（#871 で Service 側で除くようにした）
        $expectedUserIds = array_values(array_diff(array_map(
            static fn ($group) => (int)$group->i_id_user,
            $roomInfo->m_user_group
        ), [6]));
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

        $roomTable->updateAll(['i_del_flg' => 1], ['i_id_room' => 1]);

        $this->assertSame($before - 1, $this->service->countActiveRooms());
    }

    // ----------------------------------------------------------------
    // 現在の所属（#819）
    // ----------------------------------------------------------------

    /** 異動で抜けた所属（active_flag=1）のユーザーは部屋のメンバーに含めない */
    public function testGetUsersForRoom_excludesWithdrawnMembers(): void
    {
        TableRegistry::getTableLocator()->get('MUserGroup')->updateAll(['active_flag' => 1], ['i_id_user' => 2, 'i_id_room' => 1]);
        $roomInfo = TableRegistry::getTableLocator()->get('MRoomInfo')->get(1, contain: ['MUserGroup']);

        $ids = array_map(static fn ($u) => (int)$u->i_id_user, $this->service->getUsersForRoom($roomInfo));

        $this->assertNotContains(2, $ids);
        $this->assertContains(1, $ids);
    }

}
