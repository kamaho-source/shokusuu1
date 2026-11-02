<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\ChangeUserRole;

use App\Application\Port\AuditActor;
use App\Application\UseCase\ChangeUserRole\ChangeUserRoleInput;
use App\Application\UseCase\ChangeUserRole\ChangeUserRoleOutput;
use App\Application\UseCase\ChangeUserRole\ChangeUserRoleUseCase;
use App\Domain\Entity\UserAccount;
use App\Domain\Exception\NotFoundException;
use App\Test\TestCase\Application\Fake\FakeUserAccountRepository;
use App\Test\TestCase\Application\Fake\SpyAuditLog;
use PHPUnit\Framework\TestCase;

/**
 * ChangeUserRoleUseCase のテスト（Repository・監査ログは Fake。DB を使わない）。
 * 利用者 20 は一般、21 はブロック長。
 */
class ChangeUserRoleUseCaseTest extends TestCase
{
    private FakeUserAccountRepository $users;
    private SpyAuditLog $audit;
    private ChangeUserRoleUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new FakeUserAccountRepository();
        $this->users->users[20] = new UserAccount(20, '一般さん', 0, 0);
        $this->users->users[21] = new UserAccount(21, 'ブロック長さん', 2, 0);
        $this->audit   = new SpyAuditLog();
        $this->useCase = new ChangeUserRoleUseCase($this->users, $this->audit);
    }

    private function change(int $userId, int $role, int $offTarget, bool $overwrite = false, bool $allowSystemAdmin = false): ChangeUserRoleOutput
    {
        return $this->useCase->execute(new ChangeUserRoleInput(
            $userId, $role, $offTarget, $overwrite, $allowSystemAdmin, new AuditActor('操作者', 9, 'op', '10.0.0.1')
        ));
    }

    public function testGrant_savesAndRecordsOldAndNewValues(): void
    {
        $result = $this->change(20, 1, 1);

        $this->assertSame(ChangeUserRoleOutput::CHANGED, $result->status);
        $this->assertSame([['role', 20, 1, '操作者']], $this->users->updates);
        $this->assertCount(1, $this->audit->records);
        $log = $this->audit->records[0];
        $this->assertSame(['user', 'user_permission_change', 'm_user_info', '20', true, null], [$log['category'], $log['action'], $log['targetTable'], $log['targetId'], $log['succeeded'], $log['failureReason']]);
        $this->assertSame(['target_user_name' => '一般さん', 'old_i_admin' => 0, 'new_i_admin' => 1], $log['detail']);
        $this->assertSame('op', $log['actor']->loginId);
    }

    public function testSystemAdminFromNormalScreen_isRejectedAndRecordedAsSuspicious(): void
    {
        $result = $this->change(20, 3, 1);

        $this->assertSame(ChangeUserRoleOutput::NOT_ASSIGNABLE, $result->status);
        $this->assertSame([], $this->users->updates, '保存されている');
        $this->assertFalse($this->audit->records[0]['succeeded']);
        $this->assertSame('許可されていない権限値のため変更を拒否しました（不正値・権限昇格の疑い）', $this->audit->records[0]['failureReason']);
    }

    public function testOutOfRangeValue_isRejectedEvenOnSystemAdminScreen(): void
    {
        $this->assertSame(ChangeUserRoleOutput::NOT_ASSIGNABLE, $this->change(20, 4, 3, false, true)->status);
        $this->assertSame([], $this->users->updates);
    }

    public function testSystemAdminFromSystemAdminScreen_isAllowed(): void
    {
        $this->assertSame(ChangeUserRoleOutput::CHANGED, $this->change(20, 3, 3, false, true)->status);
        $this->assertSame(3, $this->users->users[20]->role);
    }

    public function testGrantOverOtherRole_withoutConfirmation_isConflictAndNothingIsWritten(): void
    {
        $result = $this->change(21, 1, 1);

        $this->assertSame(ChangeUserRoleOutput::CONFLICT, $result->status);
        $this->assertSame('現在は「ブロック長」です。「管理者」へ変更するには確認が必要です。画面を更新してやり直してください。', $result->message);
        $this->assertSame([], $this->users->updates);
        $this->assertSame([], $this->audit->records, '競合は記録しない（従来どおり）');
    }

    public function testGrantOverOtherRole_withConfirmation_isSaved(): void
    {
        $this->assertSame(ChangeUserRoleOutput::CHANGED, $this->change(21, 1, 1, true)->status);
        $this->assertSame(1, $this->users->users[21]->role);
    }

    public function testRevokeOfAnotherRole_isConflict(): void
    {
        // ブロック長の人に「管理者を解除」が届いても、ブロック長を消さない
        $this->assertSame(ChangeUserRoleOutput::CONFLICT, $this->change(21, 0, 1)->status);
        $this->assertSame(2, $this->users->users[21]->role);
    }

    public function testSaveFailure_isRecordedAsFailure(): void
    {
        $this->users->failSave = true;

        $this->assertSame(ChangeUserRoleOutput::SAVE_FAILED, $this->change(20, 1, 1)->status);
        $this->assertFalse($this->audit->records[0]['succeeded']);
        $this->assertSame('権限変更の保存に失敗しました', $this->audit->records[0]['failureReason']);
    }

    public function testMissingUser_isNotFound(): void
    {
        $this->expectException(NotFoundException::class);
        $this->change(999, 1, 1);
    }
}
