<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\UserEditService;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * UserEditService テスト。
 *
 * updateWithRooms の正常更新・部屋割り当て・監査ログ記録を検証する。
 */
class UserEditServiceTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TAuditLog',
    ];

    private UserEditService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new UserEditService();
    }

    private function getUserEntity(int $userId): \Cake\Datasource\EntityInterface
    {
        $table = TableRegistry::getTableLocator()->get('MUserInfo');
        return $table->get($userId, contain: ['MUserGroup']);
    }

    // ----------------------------------------------------------------
    // updateWithRooms — 正常更新
    // ----------------------------------------------------------------

    public function testUpdateWithRooms_returnsTrue(): void
    {
        $entity = $this->getUserEntity(2); // staff_user (i_del_flag=0)

        $result = $this->service->updateWithRooms(
            $entity,
            ['c_user_name' => '更新済み職員'],
            [1],
            'admin',
            1,
            '127.0.0.1'
        );

        $this->assertTrue($result);
    }

    public function testUpdateWithRooms_updatesUserName(): void
    {
        $entity = $this->getUserEntity(2);

        $this->service->updateWithRooms(
            $entity,
            ['c_user_name' => '更新テスト名前'],
            [],
            'admin'
        );

        $table   = TableRegistry::getTableLocator()->get('MUserInfo');
        $updated = $table->get(2);
        $this->assertSame('更新テスト名前', $updated->c_user_name);
    }

    public function testUpdateWithRooms_emptyRoomIds_deletesExistingGroups(): void
    {
        $entity = $this->getUserEntity(2);

        $this->service->updateWithRooms(
            $entity,
            ['c_user_name' => '職員ユーザー'],
            [], // 部屋なし
            'admin'
        );

        $userGroupTable = TableRegistry::getTableLocator()->get('MUserGroup');
        $count = $userGroupTable->find()
            ->where(['i_id_user' => 2])
            ->count();

        $this->assertSame(0, $count);
    }

    public function testUpdateWithRooms_withRoomId_createsUserGroup(): void
    {
        $entity = $this->getUserEntity(3);

        $this->service->updateWithRooms(
            $entity,
            ['c_user_name' => '一般ユーザー'],
            [1],
            'admin'
        );

        $userGroupTable = TableRegistry::getTableLocator()->get('MUserGroup');
        $count = $userGroupTable->find()
            ->where(['i_id_user' => 3, 'i_id_room' => 1])
            ->count();

        $this->assertSame(1, $count);
    }

    // ----------------------------------------------------------------
    // updateWithRooms — 監査ログが記録される
    // ----------------------------------------------------------------

    public function testUpdateWithRooms_createsAuditLog(): void
    {
        $auditTable = TableRegistry::getTableLocator()->get('TAuditLog');
        $before     = $auditTable->find()->count();

        $entity = $this->getUserEntity(2);
        $this->service->updateWithRooms($entity, ['c_user_name' => '監査テスト'], [], 'admin', 5);

        $this->assertSame($before + 1, $auditTable->find()->count());
    }
}
