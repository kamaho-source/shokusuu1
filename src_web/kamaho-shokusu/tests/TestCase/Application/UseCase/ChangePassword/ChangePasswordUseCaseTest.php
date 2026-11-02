<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\ChangePassword;

use App\Application\Port\AuditActor;
use App\Application\UseCase\ChangePassword\ChangePasswordInput;
use App\Application\UseCase\ChangePassword\ChangePasswordOutput;
use App\Application\UseCase\ChangePassword\ChangePasswordUseCase;
use App\Domain\Entity\UserAccount;
use App\Test\TestCase\Application\Fake\FakeUserAccountRepository;
use App\Test\TestCase\Application\Fake\SpyAuditLog;
use PHPUnit\Framework\TestCase;

/**
 * ChangePasswordUseCase のテスト（Repository・監査ログは Fake）。
 */
class ChangePasswordUseCaseTest extends TestCase
{
    private FakeUserAccountRepository $users;
    private SpyAuditLog $audit;
    private ChangePasswordUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new FakeUserAccountRepository();
        $this->users->users[20] = new UserAccount(20, '児童さん', 0, 0, 0);
        $this->users->users[21] = new UserAccount(21, '削除済みさん', 0, 0, 1);
        $this->audit   = new SpyAuditLog();
        $this->useCase = new ChangePasswordUseCase($this->users, $this->audit);
    }

    private function change(?int $userId, string $new, string $confirm, bool $byAdmin = true): ChangePasswordOutput
    {
        return $this->useCase->execute(new ChangePasswordInput($userId, $new, $confirm, new AuditActor('管理者', 1, 'admin', '10.0.0.1'), $byAdmin));
    }

    public function testSelectableUsers_excludesDeleted(): void
    {
        $this->assertSame([20 => '児童さん'], $this->useCase->selectableUsers());
    }

    public function testAdminChange_savesAndRecordsTargetName(): void
    {
        $result = $this->change(20, 'new-pass', 'new-pass');

        $this->assertSame(ChangePasswordOutput::CHANGED, $result->status);
        $this->assertSame([20 => 'new-pass'], $this->users->passwords);
        $this->assertSame('user_password_change_admin', $this->audit->records[0]['action']);
        $this->assertSame(['target_user_name' => '児童さん'], $this->audit->records[0]['detail']);
        $this->assertSame('20', $this->audit->records[0]['targetId']);
    }

    public function testAdmin_cannotChangeDeletedOrUnselectedUser(): void
    {
        foreach ([21, 999, null] as $id) {
            $result = $this->change($id, 'new-pass', 'new-pass');
            $this->assertSame('ユーザーを選択してください。', $result->message, (string)$id);
        }
        $this->assertSame([], $this->users->passwords);
        $this->assertSame([], $this->audit->records);
    }

    public function testMismatchOrTooShort_isRejected(): void
    {
        $this->assertSame('新しいパスワードが一致しません。', $this->change(20, 'aa', 'bb')->message);
        $this->assertSame('新しいパスワードは2文字以上にしてください。', $this->change(20, 'a', 'a')->message);
        $this->assertSame([], $this->users->passwords);
    }

    /** 長さは、管理者の変更はバイト数、本人の変更は文字数で数える（従来どおり） */
    public function testLength_adminCountsBytesSelfCountsCharacters(): void
    {
        $this->assertSame(ChangePasswordOutput::CHANGED, $this->change(20, 'あ', 'あ')->status);
        $this->assertSame('新しいパスワードは2文字以上にしてください。', $this->change(20, 'あ', 'あ', byAdmin: false)->message);
    }

    public function testSelfChange_recordsWithoutDetail(): void
    {
        $result = $this->change(20, 'my-pass', 'my-pass', byAdmin: false);

        $this->assertSame(ChangePasswordOutput::CHANGED, $result->status);
        $this->assertSame('user_password_change_self', $this->audit->records[0]['action']);
        $this->assertNull($this->audit->records[0]['detail']);
    }

    public function testSaveFails_returnsSaveFailedWithoutAudit(): void
    {
        $this->users->failSave = true;

        $this->assertSame(ChangePasswordOutput::SAVE_FAILED, $this->change(20, 'new-pass', 'new-pass')->status);
        $this->assertSame([], $this->audit->records);
    }
}
