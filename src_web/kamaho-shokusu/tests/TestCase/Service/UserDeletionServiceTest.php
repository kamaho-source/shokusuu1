<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\UserDeletionService;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * UserDeletionService テスト
 *
 * softDelete() が i_del_flag を立てるとともに監査ログを記録することを検証する。
 */
class UserDeletionServiceTest extends TestCase
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

    private function userTable(): \Cake\ORM\Table
    {
        return TableRegistry::getTableLocator()->get('MUserInfo');
    }

    // ----------------------------------------------------------------
    // softDelete — 基本動作
    // ----------------------------------------------------------------

    public function testSoftDelete_returnsTrue(): void
    {
        $user = $this->userTable()->get(1);
        $service = new UserDeletionService();

        $result = $service->softDelete($user, 'admin', 99, '10.0.0.1');

        $this->assertTrue($result);
    }

    public function testSoftDelete_setsDelFlag(): void
    {
        $user = $this->userTable()->get(1);
        $user->i_del_flag = 0;
        $this->userTable()->save($user);

        $user = $this->userTable()->get(1);
        $service = new UserDeletionService();
        $service->softDelete($user, 'admin', 99);

        $refreshed = $this->userTable()->get(1);
        $this->assertSame(1, (int)$refreshed->i_del_flag);
    }

    // ----------------------------------------------------------------
    // softDelete — 監査ログ記録
    // ----------------------------------------------------------------

    public function testSoftDelete_createsAuditLog(): void
    {
        $before = $this->countAuditLogs();
        $user   = $this->userTable()->get(1);

        (new UserDeletionService())->softDelete($user, 'admin', 99, '192.168.0.1');

        $this->assertSame($before + 1, $this->countAuditLogs());
    }

    public function testSoftDelete_auditLog_fields(): void
    {
        $user = $this->userTable()->get(1);

        (new UserDeletionService())->softDelete($user, 'adminuser', 7, '10.0.0.1');

        $log = $this->lastAuditLog();
        $this->assertSame('user',         $log->c_category);
        $this->assertSame('user_delete',  $log->c_action);
        $this->assertSame('adminuser',    $log->c_actor_user_name);
        $this->assertSame(7,              (int)$log->i_actor_user_id);
        $this->assertSame('m_user_info',  $log->c_target_table);
        $this->assertSame('1',            $log->c_target_id);
        $this->assertSame('10.0.0.1',     $log->c_ip_address);
        $this->assertSame(1,              (int)$log->i_result);
    }

    public function testSoftDelete_auditLog_containsTargetUserName(): void
    {
        $user = $this->userTable()->get(1);

        (new UserDeletionService())->softDelete($user, 'admin', 1);

        $log     = $this->lastAuditLog();
        $decoded = json_decode($log->c_detail, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('target_user_name', $decoded);
    }

    public function testSoftDelete_auditLog_nullIp_whenEmpty(): void
    {
        $user = $this->userTable()->get(1);

        (new UserDeletionService())->softDelete($user, 'admin', 1, '');

        $log = $this->lastAuditLog();
        $this->assertNull($log->c_ip_address);
    }
}
