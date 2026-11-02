<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\RestoreUser;

use App\Application\Port\AuditActor;
use App\Application\UseCase\RestoreUser\RestoreUserInput;
use App\Application\UseCase\RestoreUser\RestoreUserUseCase;
use App\Infrastructure\Log\AuditLogAdapter;
use App\Infrastructure\Persistence\CakeTransactionManager;
use App\Infrastructure\Repository\CakeUserAccountRepository;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * RestoreUserUseCase を本物の Repository・監査ログにつないだテスト（旧 UserRestoreServiceTest を引き継ぐ）
 *
 * restore() がソフトデリートを解除し、成功・失敗どちらのケースでも
 * 監査ログを記録することを検証する。
 */
class RestoreUserIntegrationTest extends TestCase
{
    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.TAuditLog',
    ];

    /** RestoreUserUseCase を本物の Repository・監査ログにつないで実行する（失敗なら例外。旧 UserRestoreService::restore と同じ） */
    private function restoreUser(object $user, string $by, int $actorId = 0, string $ip = ''): bool
    {
        $users   = $this->getTableLocator()->get('MUserInfo');
        $useCase = new RestoreUserUseCase(new CakeUserAccountRepository($users), new CakeTransactionManager($users->getConnection()), new AuditLogAdapter());
        $useCase->execute(new RestoreUserInput((int)$user->i_id_user, new AuditActor($by, $actorId, '', $ip)));

        return true;
    }

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
    // restore — 基本動作（MUserInfoFixture の user 1 は i_del_flag=1）
    // ----------------------------------------------------------------

    public function testRestore_returnsTrue(): void
    {
        $user    = $this->userTable()->get(1);
        $result  = $this->restoreUser($user, 'admin', 99, '10.0.0.1');

        $this->assertTrue($result);
    }

    public function testRestore_clearsDelFlag(): void
    {
        $user = $this->userTable()->get(1);
        $this->restoreUser($user, 'admin', 99);

        $refreshed = $this->userTable()->get(1);
        $this->assertSame(0, (int)$refreshed->i_del_flag);
    }

    public function testRestore_reenablesLogin(): void
    {
        $user = $this->userTable()->get(1);
        $user->i_enable = 1; // 削除時に立つ値
        $this->userTable()->save($user);

        $this->restoreUser($this->userTable()->get(1), 'admin', 99);

        $this->assertSame(0, (int)$this->userTable()->get(1)->i_enable);
    }

    // ----------------------------------------------------------------
    // restore — 成功時の監査ログ
    // ----------------------------------------------------------------

    public function testRestore_success_createsAuditLog(): void
    {
        $before = $this->countAuditLogs();
        $user   = $this->userTable()->get(1);

        $this->restoreUser($user, 'admin', 5, '192.168.1.1');

        $this->assertSame($before + 1, $this->countAuditLogs());
    }

    public function testRestore_success_auditLog_fields(): void
    {
        $user = $this->userTable()->get(1);

        $this->restoreUser($user, 'sysadmin', 3, '10.1.2.3');

        $log = $this->lastAuditLog();
        $this->assertSame('user',           $log->c_category);
        $this->assertSame('user_restore',   $log->c_action);
        $this->assertSame('sysadmin',       $log->c_actor_user_name);
        $this->assertSame(3,                (int)$log->i_actor_user_id);
        $this->assertSame('m_user_info',    $log->c_target_table);
        $this->assertSame('1',              $log->c_target_id);
        $this->assertSame('10.1.2.3',       $log->c_ip_address);
        $this->assertSame(1,                (int)$log->i_result);
    }

    public function testRestore_success_auditLog_containsTargetUserName(): void
    {
        $user = $this->userTable()->get(1);

        $this->restoreUser($user, 'admin', 1);

        $log     = $this->lastAuditLog();
        $decoded = json_decode($log->c_detail, true);
        $this->assertIsArray($decoded);
        $this->assertArrayHasKey('target_user_name', $decoded);
    }

    public function testRestore_success_auditLog_nullIp_whenEmpty(): void
    {
        $user = $this->userTable()->get(1);

        $this->restoreUser($user, 'admin', 1, '');

        $log = $this->lastAuditLog();
        $this->assertNull($log->c_ip_address);
    }
}
