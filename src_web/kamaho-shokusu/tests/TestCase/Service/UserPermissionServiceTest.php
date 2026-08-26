<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\UserPermissionService;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * UserPermissionService テスト
 *
 * updatePermission() が i_admin を更新し、変更前後の値を含む監査ログを
 * 記録することを検証する。
 */
class UserPermissionServiceTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserInfo',
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
        return $this->auditTable()->find()->orderBy(['i_id_audit' => 'DESC'])->first();
    }

    private function userTable(): \Cake\ORM\Table
    {
        return TableRegistry::getTableLocator()->get('MUserInfo');
    }

    // ----------------------------------------------------------------
    // updatePermission — 基本動作
    // ----------------------------------------------------------------

    public function testUpdatePermission_returnsTrue(): void
    {
        $user   = $this->userTable()->get(1);
        $result = (new UserPermissionService())->updatePermission($user, 0, 'sysadmin', 99, '10.0.0.1');

        $this->assertTrue($result);
    }

    public function testUpdatePermission_updatesIAdmin(): void
    {
        $user = $this->userTable()->get(1);
        (new UserPermissionService())->updatePermission($user, 2, 'sysadmin', 99);

        $refreshed = $this->userTable()->get(1);
        $this->assertSame(2, (int)$refreshed->i_admin);
    }

    // ----------------------------------------------------------------
    // updatePermission — 監査ログ記録
    // ----------------------------------------------------------------

    public function testUpdatePermission_createsAuditLog(): void
    {
        $before = $this->countAuditLogs();
        $user   = $this->userTable()->get(1);

        (new UserPermissionService())->updatePermission($user, 3, 'sysadmin', 5, '192.168.1.1', '', true);

        $this->assertSame($before + 1, $this->countAuditLogs());
    }

    public function testUpdatePermission_auditLog_fields(): void
    {
        $user = $this->userTable()->get(1);

        (new UserPermissionService())->updatePermission($user, 0, 'operator', 6, '10.9.8.7');

        $log = $this->lastAuditLog();
        $this->assertSame('user',                    $log->c_category);
        $this->assertSame('user_permission_change',  $log->c_action);
        $this->assertSame('operator',                $log->c_actor_user_name);
        $this->assertSame(6,                         (int)$log->i_actor_user_id);
        $this->assertSame('m_user_info',             $log->c_target_table);
        $this->assertSame('1',                       $log->c_target_id);
        $this->assertSame('10.9.8.7',                $log->c_ip_address);
        $this->assertSame(1,                         (int)$log->i_result);
    }

    public function testUpdatePermission_auditLog_detail_hasOldAndNewAdminValues(): void
    {
        $user     = $this->userTable()->get(1);
        $oldAdmin = (int)$user->i_admin;

        (new UserPermissionService())->updatePermission($user, 3, 'sysadmin', 1, '', '', true);

        $log     = $this->lastAuditLog();
        $decoded = json_decode($log->c_detail, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('old_i_admin', $decoded);
        $this->assertArrayHasKey('new_i_admin', $decoded);
        $this->assertSame($oldAdmin, $decoded['old_i_admin']);
        $this->assertSame(3,         $decoded['new_i_admin']);
    }

    public function testUpdatePermission_auditLog_detail_hasTargetUserName(): void
    {
        $user = $this->userTable()->get(1);

        (new UserPermissionService())->updatePermission($user, 1, 'admin', 1);

        $log     = $this->lastAuditLog();
        $decoded = json_decode($log->c_detail, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('target_user_name', $decoded);
    }

    // ----------------------------------------------------------------
    // 各権限値への変更
    // ----------------------------------------------------------------

    /**
     * @dataProvider adminValueProvider
     */
    public function testUpdatePermission_allAdminValues_succeed(int $newValue): void
    {
        $user   = $this->userTable()->get(1);
        $result = (new UserPermissionService())->updatePermission($user, $newValue, 'admin', 1);

        $this->assertTrue($result);

        $refreshed = $this->userTable()->get(1);
        $this->assertSame($newValue, (int)$refreshed->i_admin);
    }

    public static function adminValueProvider(): array
    {
        return [
            'general (0)'        => [0],
            'admin (1)'          => [1],
            'block_leader (2)'   => [2],
        ];
    }

    // ----------------------------------------------------------------
    // updatePermission — SYSTEM_ADMIN(3) への権限昇格制御
    // （一般ADMINがSYSTEM_ADMINへ任意ユーザーを昇格させる脆弱性の修正）
    // ----------------------------------------------------------------

    /**
     * updateAdminStatus / updateUserLevel 相当の呼び出し（allowSystemAdmin=false）で
     * SYSTEM_ADMIN(3) への変更を試みると拒否されること。
     */
    public function testUpdatePermission_systemAdminValue_rejectedWhenNotAllowed(): void
    {
        $user     = $this->userTable()->get(1);
        $oldAdmin = (int)$user->i_admin;

        $result = (new UserPermissionService())->updatePermission($user, 3, 'admin', 1);

        $this->assertFalse($result);

        $refreshed = $this->userTable()->get(1);
        $this->assertSame($oldAdmin, (int)$refreshed->i_admin);
    }

    /**
     * updateSystemAdminStatus 相当の呼び出し（allowSystemAdmin=true）では
     * SYSTEM_ADMIN(3) への変更が許可されること。
     */
    public function testUpdatePermission_systemAdminValue_allowedWhenExplicitlyPermitted(): void
    {
        $user   = $this->userTable()->get(1);
        $result = (new UserPermissionService())->updatePermission($user, 3, 'sysadmin', 1, '', '', true);

        $this->assertTrue($result);

        $refreshed = $this->userTable()->get(1);
        $this->assertSame(3, (int)$refreshed->i_admin);
    }

    /**
     * 範囲外の値（0〜3以外）は allowSystemAdmin の値にかかわらず常に拒否されること。
     */
    public function testUpdatePermission_outOfRangeValue_alwaysRejected(): void
    {
        $user     = $this->userTable()->get(1);
        $oldAdmin = (int)$user->i_admin;

        $result = (new UserPermissionService())->updatePermission($user, 4, 'sysadmin', 1, '', '', true);

        $this->assertFalse($result);

        $refreshed = $this->userTable()->get(1);
        $this->assertSame($oldAdmin, (int)$refreshed->i_admin);
    }

    /**
     * SYSTEM_ADMIN(3) への遷移が拒否された場合も、監査ログには
     * 失敗（i_result=0）として記録されること。
     */
    public function testUpdatePermission_systemAdminValue_rejected_stillRecordsAuditLog(): void
    {
        $before = $this->countAuditLogs();
        $user   = $this->userTable()->get(1);

        (new UserPermissionService())->updatePermission($user, 3, 'admin', 1);

        $this->assertSame($before + 1, $this->countAuditLogs());

        $log = $this->lastAuditLog();
        $this->assertSame(0, (int)$log->i_result);
    }
}
