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

    /**
     * 部屋異動で無効化された旧部屋(active_flag=1)の行は、ユーザー編集の保存で復活させない。
     */
    public function testUpdateWithRooms_doesNotReviveInactiveGroup(): void
    {
        $userGroupTable = TableRegistry::getTableLocator()->get('MUserGroup');
        // user 2: 部屋1(現在所属・active_flag=0)。部屋2は異動で無効化された旧所属として追加する。
        $userGroupTable->saveOrFail($userGroupTable->newEntity([
            'i_id_user'     => 2,
            'i_id_room'     => 2,
            'active_flag'   => 1,
            'dt_create'     => '2024-09-07 15:59:52',
            'c_create_user' => 'system',
            'dt_update'     => '2024-09-07 15:59:52',
            'c_update_user' => 'system',
        ]));

        $entity = $this->getUserEntity(2);
        // 画面では現所属の部屋1だけがチェックされた状態で名前だけ直して保存する
        $this->service->updateWithRooms($entity, ['c_user_name' => '名前だけ修正'], [1], 'admin');

        $active = $userGroupTable->find()->where(['i_id_user' => 2, 'active_flag' => 0])->all()->extract('i_id_room')->toList();
        $this->assertSame([1], array_map('intval', $active));

        $old = $userGroupTable->find()->where(['i_id_user' => 2, 'i_id_room' => 2])->first();
        $this->assertNotNull($old);
        $this->assertSame(1, (int)$old->active_flag);
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
