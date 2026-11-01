<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\ChangeReportAccess;

use App\Application\Port\AuditActor;
use App\Application\UseCase\ChangeReportAccess\ChangeReportAccessInput;
use App\Application\UseCase\ChangeReportAccess\ChangeReportAccessUseCase;
use App\Domain\Entity\UserAccount;
use App\Domain\Exception\NotFoundException;
use App\Test\TestCase\Application\Fake\FakeUserAccountRepository;
use App\Test\TestCase\Application\Fake\SpyAuditLog;
use PHPUnit\Framework\TestCase;

/**
 * ChangeReportAccessUseCase のテスト（Repository・監査ログは Fake）。
 */
class ChangeReportAccessUseCaseTest extends TestCase
{
    private FakeUserAccountRepository $users;
    private SpyAuditLog $audit;
    private ChangeReportAccessUseCase $useCase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->users = new FakeUserAccountRepository();
        $this->users->users[20] = new UserAccount(20, '一般さん', 0, 0);
        $this->audit   = new SpyAuditLog();
        $this->useCase = new ChangeReportAccessUseCase($this->users, $this->audit);
    }

    private function change(int $userId, bool $enabled): bool
    {
        return $this->useCase->execute(new ChangeReportAccessInput($userId, $enabled, new AuditActor('操作者', 9, 'op', '')))->saved;
    }

    public function testEnableAndDisable_saveAndRecord(): void
    {
        $this->assertTrue($this->change(20, true));
        $this->assertTrue($this->change(20, false));

        $this->assertSame([['reportAccess', 20, 1, '操作者'], ['reportAccess', 20, 0, '操作者']], $this->users->updates);
        $this->assertSame(['target_user_name' => '一般さん', 'i_report_access' => 1], $this->audit->records[0]['detail']);
        $this->assertSame('user_report_access_change', $this->audit->records[0]['action']);
        $this->assertTrue($this->audit->records[0]['succeeded']);
    }

    public function testSaveFailure_returnsFalseAndIsNotRecorded(): void
    {
        $this->users->failSave = true;

        $this->assertFalse($this->change(20, true));
        $this->assertSame([], $this->audit->records, '従来どおり、失敗は記録しない');
    }

    public function testMissingUser_isNotFound(): void
    {
        $this->assertFalse($this->useCase->targetExists(999));
        $this->expectException(NotFoundException::class);
        $this->change(999, true);
    }
}
