<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\UserCreateService;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * UserCreateService テスト
 *
 * saveWithRooms() が保存とともに監査ログを記録することを検証する。
 */
class UserCreateServiceTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.TAuditLog',
    ];

    private function auditTable(): \Cake\ORM\Table
    {
        return TableRegistry::getTableLocator()->get('TAuditLog');
    }

    private function countAuditLogs(): int
    {
        return $this->auditTable()->find()->count();
    }

    private function lastAuditLog(): ?\Cake\Datasource\EntityInterface
    {
        return $this->auditTable()->find()->order(['i_id_audit' => 'DESC'])->first();
    }

    private function newUserEntity(string $loginAccount = 'new_test_user'): \Cake\Datasource\EntityInterface
    {
        $table = TableRegistry::getTableLocator()->get('MUserInfo');
        return $table->newEntity([
            'c_login_account' => $loginAccount,
            'c_login_passwd'  => 'hashed_password',
            'c_user_name'     => 'テスト 太郎',
            'i_admin'         => 0,
            'i_user_level'    => 0,
            'i_user_gender'   => 1,
            'i_user_age'      => 30,
            'i_user_rank'     => 1,
            'i_disp_no'       => 99,
            'i_enable'        => 0,
            'i_del_flag'      => 0,
            'dt_create'       => date('Y-m-d H:i:s'),
            'c_create_user'   => 'admin',
        ]);
    }

    // ----------------------------------------------------------------
    // saveWithRooms — 基本動作
    // ----------------------------------------------------------------

    public function testSaveWithRooms_returnsTrue(): void
    {
        $entity  = $this->newUserEntity();
        $service = new UserCreateService();

        $result = $service->saveWithRooms($entity, [], 'admin', 1, '10.0.0.1');

        $this->assertTrue($result);
    }

    public function testSaveWithRooms_persists_user(): void
    {
        $entity = $this->newUserEntity('persist_test_user');
        (new UserCreateService())->saveWithRooms($entity, [], 'admin');

        $found = TableRegistry::getTableLocator()->get('MUserInfo')
            ->find()
            ->where(['c_login_account' => 'persist_test_user'])
            ->first();

        $this->assertNotNull($found);
    }

    // ----------------------------------------------------------------
    // saveWithRooms — 監査ログ記録（成功）
    // ----------------------------------------------------------------

    public function testSaveWithRooms_success_createsAuditLog(): void
    {
        $before  = $this->countAuditLogs();
        $entity  = $this->newUserEntity();

        (new UserCreateService())->saveWithRooms($entity, [], 'admin', 2, '10.0.0.2');

        $this->assertSame($before + 1, $this->countAuditLogs());
    }

    public function testSaveWithRooms_success_auditLog_fields(): void
    {
        $entity = $this->newUserEntity('audit_log_user');

        (new UserCreateService())->saveWithRooms($entity, [], 'adminuser', 8, '10.1.2.3');

        $log = $this->lastAuditLog();
        $this->assertSame('user',          $log->c_category);
        $this->assertSame('user_create',   $log->c_action);
        $this->assertSame('adminuser',     $log->c_actor_user_name);
        $this->assertSame(8,               (int)$log->i_actor_user_id);
        $this->assertSame('m_user_info',   $log->c_target_table);
        $this->assertSame('10.1.2.3',      $log->c_ip_address);
        $this->assertSame(1,               (int)$log->i_result);
    }

    public function testSaveWithRooms_success_auditLog_detail_hasUserAndLoginAccount(): void
    {
        $entity = $this->newUserEntity('detail_check_user');

        (new UserCreateService())->saveWithRooms($entity, [], 'admin', 1);

        $log     = $this->lastAuditLog();
        $decoded = json_decode($log->c_detail, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('user_name',      $decoded);
        $this->assertArrayHasKey('login_account',  $decoded);
        $this->assertSame('テスト 太郎',            $decoded['user_name']);
        $this->assertSame('detail_check_user',     $decoded['login_account']);
    }

    // ----------------------------------------------------------------
    // nextDisplayNo
    // ----------------------------------------------------------------

    public function testNextDisplayNo_returnsMaxPlusOne(): void
    {
        $service = new UserCreateService();
        $result  = $service->nextDisplayNo();
        $this->assertIsInt($result);
        $this->assertGreaterThanOrEqual(1, $result);
    }

    // ----------------------------------------------------------------
    // loginAccountExists
    // ----------------------------------------------------------------

    public function testLoginAccountExists_existing_returnsTrue(): void
    {
        $service = new UserCreateService();
        $this->assertTrue($service->loginAccountExists('Lorem ipsum dolor sit amet'));
    }

    public function testLoginAccountExists_nonExisting_returnsFalse(): void
    {
        $service = new UserCreateService();
        $this->assertFalse($service->loginAccountExists('definitely_does_not_exist_xyz'));
    }
}
