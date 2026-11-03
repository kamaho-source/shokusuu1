<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\RecordLogin;

use App\Application\Port\AuditActor;
use App\Application\Query\UserQueryInterface;
use App\Application\UseCase\RecordLogin\RecordLoginInput;
use App\Application\UseCase\RecordLogin\RecordLoginUseCase;
use App\Application\UseCase\RecordLoginFailure\RecordLoginFailureInput;
use App\Application\UseCase\RecordLoginFailure\RecordLoginFailureUseCase;
use App\Application\UseCase\RecordLogout\RecordLogoutInput;
use App\Application\UseCase\RecordLogout\RecordLogoutUseCase;
use App\Domain\Entity\UserAccount;
use App\Test\TestCase\Application\Fake\SpyAuditLog;
use PHPUnit\Framework\TestCase;

/**
 * ログイン・ログイン失敗・ログアウトの監査ログ（MUserInfoController にあったときと同じ action・detail・結果）。
 */
class RecordLoginUseCasesTest extends TestCase
{
    private SpyAuditLog $audit;

    protected function setUp(): void
    {
        parent::setUp();
        $this->audit = new SpyAuditLog();
    }

    public function testLogin_recordsSuccessWithNameFallbacks(): void
    {
        $useCase = new RecordLoginUseCase($this->audit);
        $useCase->execute(new RecordLoginInput(5, '山田', 'yamada', '10.0.0.1'));
        $useCase->execute(new RecordLoginInput(6, null, 'noname', '10.0.0.1'));
        $useCase->execute(new RecordLoginInput(7, null, null, '10.0.0.1'));

        $first = $this->audit->records[0];
        $this->assertSame(['user', 'user_login', 'm_user_info', '5', null, true], [$first['category'], $first['action'], $first['targetTable'], $first['targetId'], $first['detail'], $first['succeeded']]);
        $this->assertEquals(new AuditActor('山田', 5, 'yamada', '10.0.0.1'), $first['actor']);
        $this->assertSame('noname', $this->audit->records[1]['actor']->name, '氏名が無ければログインID');
        $this->assertSame('不明', $this->audit->records[2]['actor']->name);
        $this->assertSame('', $this->audit->records[2]['actor']->loginId);
    }

    public function testLoginFailure_actorIsTheAccountOfTheTypedLoginId(): void
    {
        $users = $this->createStub(UserQueryInterface::class);
        $users->method('findByLoginAccount')->willReturnMap([
            ['admin_user', new UserAccount(1, '管理者', 1, 0, 0, 0, 'admin_user')],
            ['nobody', null],
        ]);
        $useCase = new RecordLoginFailureUseCase($users, $this->audit);
        $useCase->execute(new RecordLoginFailureInput('admin_user', '10.0.0.2'));
        $useCase->execute(new RecordLoginFailureInput('nobody', '10.0.0.2'));

        $known = $this->audit->records[0];
        $this->assertSame(['user', 'user_login_failed', 'm_user_info', null, ['login_account' => 'admin_user'], false, 'ユーザー名またはパスワードが一致しません'],
            [$known['category'], $known['action'], $known['targetTable'], $known['targetId'], $known['detail'], $known['succeeded'], $known['failureReason']]);
        $this->assertEquals(new AuditActor('管理者', 1, 'admin_user', '10.0.0.2'), $known['actor']);
        $this->assertEquals(new AuditActor(AuditActor::UNKNOWN_ACCOUNT_NAME, 0, 'nobody', '10.0.0.2'), $this->audit->records[1]['actor']);
    }

    public function testLoginFailure_emptyLoginIdDoesNotLookUp(): void
    {
        $users = $this->createMock(UserQueryInterface::class);
        $users->expects($this->never())->method('findByLoginAccount');
        (new RecordLoginFailureUseCase($users, $this->audit))->execute(new RecordLoginFailureInput('', '10.0.0.2'));

        $this->assertSame(AuditActor::UNKNOWN_ACCOUNT_NAME, $this->audit->records[0]['actor']->name);
    }

    public function testLoginFailure_longInputIsTruncatedInTheAuditLog(): void
    {
        $users = $this->createStub(UserQueryInterface::class);
        $long = str_repeat('あ', 300);
        (new RecordLoginFailureUseCase($users, $this->audit))->execute(new RecordLoginFailureInput($long, '10.0.0.2'));

        $record = $this->audit->records[0];
        $this->assertSame(str_repeat('あ', 255), $record['detail']['login_account'], '入力は 255 文字までしか残さない');
        $this->assertSame(str_repeat('あ', 255), $record['actor']->loginId);
    }

    public function testLogout_recordsSuccess(): void
    {
        $useCase = new RecordLogoutUseCase($this->audit);
        $useCase->execute(new RecordLogoutInput(5, '山田', 'yamada', '10.0.0.3'));
        $useCase->execute(new RecordLogoutInput(6, null, null, '10.0.0.3'));

        $first = $this->audit->records[0];
        $this->assertSame(['user', 'user_logout', 'm_user_info', '5', null, true], [$first['category'], $first['action'], $first['targetTable'], $first['targetId'], $first['detail'], $first['succeeded']]);
        $this->assertEquals(new AuditActor('山田', 5, 'yamada', '10.0.0.3'), $first['actor']);
        $this->assertSame('不明', $this->audit->records[1]['actor']->name, 'ログアウトは氏名が無ければ「不明」（ログインIDにはしない）');
    }
}
