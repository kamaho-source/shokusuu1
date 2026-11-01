<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\ChangeUserRole;

use App\Application\Port\AuditActor;
use App\Application\UseCase\ChangeReportAccess\ChangeReportAccessInput;
use App\Application\UseCase\ChangeReportAccess\ChangeReportAccessUseCase;
use App\Application\UseCase\ChangeUserRole\ChangeUserRoleInput;
use App\Application\UseCase\ChangeUserRole\ChangeUserRoleOutput;
use App\Application\UseCase\ChangeUserRole\ChangeUserRoleUseCase;
use App\Infrastructure\Log\AuditLogAdapter;
use App\Infrastructure\Repository\CakeUserAccountRepository;
use Cake\Datasource\EntityInterface;
use Cake\ORM\Table;
use Cake\TestSuite\TestCase;

/**
 * 権限・レポート閲覧権の変更を、本物の Repository と監査ログ（t_audit_log）につないで確かめる。
 * （旧 UserPermissionServiceTest の確認内容を引き継ぐ）
 */
class ChangeUserRoleIntegrationTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserInfo',
        'app.TAuditLog',
    ];

    private function users(): Table
    {
        return $this->getTableLocator()->get('MUserInfo');
    }

    private function changeRole(int $role, bool $allowSystemAdmin = false, string $actor = 'sysadmin', int $actorId = 99, string $ip = '10.0.0.1'): ChangeUserRoleOutput
    {
        $useCase = new ChangeUserRoleUseCase(new CakeUserAccountRepository($this->users()), new AuditLogAdapter());

        // 解除対象は付ける権限と同じ（付与のとき）。競合しないよう overwrite は true
        return $useCase->execute(new ChangeUserRoleInput(1, $role, $role === 0 ? 1 : $role, true, $allowSystemAdmin, new AuditActor($actor, $actorId, 'login_' . $actor, $ip)));
    }

    private function auditCount(): int
    {
        return $this->getTableLocator()->get('TAuditLog')->find()->count();
    }

    private function lastAudit(): EntityInterface
    {
        return $this->getTableLocator()->get('TAuditLog')->find()->orderBy(['i_id_audit' => 'DESC'])->firstOrFail();
    }

    /**
     * @dataProvider assignableRoles
     */
    public function testEachAssignableRole_isSavedToDb(int $role): void
    {
        $this->assertSame(ChangeUserRoleOutput::CHANGED, $this->changeRole($role)->status);
        $this->assertSame($role, (int)$this->users()->get(1)->i_admin);
    }

    /** @return array<string, array{int}> */
    public static function assignableRoles(): array
    {
        return ['一般 (0)' => [0], '管理者 (1)' => [1], 'ブロック長 (2)' => [2]];
    }

    public function testAuditLog_fieldsAndDetail(): void
    {
        $oldAdmin = (int)$this->users()->get(1)->i_admin;
        $before   = $this->auditCount();

        $this->changeRole(2, false, 'operator', 6, '10.9.8.7');

        $this->assertSame($before + 1, $this->auditCount());
        $log = $this->lastAudit();
        $this->assertSame('user', $log->c_category);
        $this->assertSame('user_permission_change', $log->c_action);
        $this->assertSame('operator', $log->c_actor_user_name);
        $this->assertSame('login_operator', $log->c_actor_login_id);
        $this->assertSame(6, (int)$log->i_actor_user_id);
        $this->assertSame('m_user_info', $log->c_target_table);
        $this->assertSame('1', $log->c_target_id);
        $this->assertSame('10.9.8.7', $log->c_ip_address);
        $this->assertSame(1, (int)$log->i_result);
        $detail = json_decode((string)$log->c_detail, true);
        $this->assertSame($oldAdmin, $detail['old_i_admin']);
        $this->assertSame(2, $detail['new_i_admin']);
        $this->assertArrayHasKey('target_user_name', $detail);
    }

    public function testSystemAdmin_onlyFromSystemAdminScreen(): void
    {
        $oldAdmin = (int)$this->users()->get(1)->i_admin;
        $before   = $this->auditCount();

        $this->assertSame(ChangeUserRoleOutput::NOT_ASSIGNABLE, $this->changeRole(3)->status);
        $this->assertSame($oldAdmin, (int)$this->users()->get(1)->i_admin, '一般の画面からシステム管理者になっている');
        $this->assertSame($before + 1, $this->auditCount(), '拒否も記録する');
        $this->assertSame(0, (int)$this->lastAudit()->i_result);

        $this->assertSame(ChangeUserRoleOutput::CHANGED, $this->changeRole(3, true)->status);
        $this->assertSame(3, (int)$this->users()->get(1)->i_admin);
    }

    public function testOutOfRangeValue_isAlwaysRejected(): void
    {
        $oldAdmin = (int)$this->users()->get(1)->i_admin;

        $this->assertSame(ChangeUserRoleOutput::NOT_ASSIGNABLE, $this->changeRole(4, true)->status);
        $this->assertSame($oldAdmin, (int)$this->users()->get(1)->i_admin);
    }

    public function testReportAccess_isSavedAndRecorded(): void
    {
        $useCase = new ChangeReportAccessUseCase(new CakeUserAccountRepository($this->users()), new AuditLogAdapter());

        $this->assertTrue($useCase->execute(new ChangeReportAccessInput(1, true, new AuditActor('sysadmin', 4, 'system_admin_user', '')))->saved);

        $user = $this->users()->get(1);
        $this->assertSame(1, (int)$user->i_report_access);
        $this->assertSame('sysadmin', $user->c_update_user);
        $log = $this->lastAudit();
        $this->assertSame('user_report_access_change', $log->c_action);
        $this->assertNull($log->c_ip_address, 'IP が空なら NULL で残す（従来どおり）');
        $this->assertSame(1, json_decode((string)$log->c_detail, true)['i_report_access']);
    }
}
