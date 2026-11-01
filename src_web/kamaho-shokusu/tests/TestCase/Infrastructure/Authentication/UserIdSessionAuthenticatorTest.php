<?php
declare(strict_types=1);

namespace App\Test\TestCase\Infrastructure\Authentication;

use App\Infrastructure\Authentication\UserIdSessionAuthenticator;
use Authentication\Authenticator\Result;
use Authentication\Identifier\TokenIdentifier;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Http\Session;
use Cake\TestSuite\TestCase;

/**
 * セッションに利用者 ID だけを保存する認証器のテスト。
 *
 * 以前の形式（Entity・配列・旧クラス名で復元できなくなった Entity）のセッションも、500 にせず読めることを確かめる。
 */
class UserIdSessionAuthenticatorTest extends TestCase
{
    protected array $fixtures = ['app.MUserInfo'];

    private Session $session;
    private ServerRequest $request;
    private UserIdSessionAuthenticator $authenticator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->session = new Session();
        $this->request = (new ServerRequest())->withAttribute('session', $this->session);
        $identifier    = new TokenIdentifier([
            'tokenField' => 'i_id_user',
            'dataField'  => 'key',
            'resolver'   => ['className' => 'Authentication.Orm', 'userModel' => 'MUserInfo'],
        ]);
        $this->authenticator = new UserIdSessionAuthenticator($identifier, ['idField' => 'i_id_user', 'identifierKey' => 'key']);
    }

    private function authenticateWith(mixed $stored): Result
    {
        $this->session->write('Auth', $stored);
        /** @var \Authentication\Authenticator\Result $result */
        $result = $this->authenticator->authenticate($this->request);

        return $result;
    }

    /** 旧クラス名で保存された Entity（クラスが無くなり __PHP_Incomplete_Class になったもの）を作る */
    private function legacyEntity(int $userId): object
    {
        $user = $this->getTableLocator()->get('MUserInfo')->get($userId);
        $legacy = 'App\\Model\\Entity\\NoSuchLegacyUserClass';
        $serialized = (string)preg_replace('/^O:\d+:"[^"]+"/', 'O:' . strlen($legacy) . ':"' . $legacy . '"', serialize($user));

        return unserialize($serialized);
    }

    public function testIdOnly_isAuthenticatedWithFreshUser(): void
    {
        $result = $this->authenticateWith(1);

        $this->assertTrue($result->isValid());
        $this->assertSame(1, (int)$result->getData()['i_id_user']);
        $this->assertSame('admin_user', $result->getData()['c_login_account']);
        $this->assertSame(1, $this->session->read('Auth'));
    }

    /**
     * 以前の形式のセッションは、読めたら利用者 ID だけの形に書き換える。
     */
    public function testLegacySessionFormats_areReadAndRewrittenToIdOnly(): void
    {
        $formats = [
            'Entity（今のクラス）' => fn() => $this->getTableLocator()->get('MUserInfo')->get(2),
            '配列'                => fn() => ['i_id_user' => 2, 'c_login_account' => 'staff_user'],
            '数字の文字列'         => fn() => '2',
            '旧クラス名の Entity'   => fn() => $this->legacyEntity(2),
        ];
        foreach ($formats as $name => $make) {
            $stored = $make();
            if ($name === '旧クラス名の Entity') {
                $this->assertInstanceOf(\__PHP_Incomplete_Class::class, $stored, '前提: 復元できない Entity になっている');
            }

            $result = $this->authenticateWith($stored);

            $this->assertTrue($result->isValid(), $name);
            $this->assertSame(2, (int)$result->getData()['i_id_user'], $name);
            $this->assertSame(2, $this->session->read('Auth'), $name . ': ID だけに書き換わっていない');
        }
    }

    /**
     * 利用者 ID を取り出せない値は、例外にせず未ログインとして扱い、セッションから消す。
     */
    public function testUnreadableSession_isUnauthenticatedWithoutException(): void
    {
        foreach (['abc', '0', '-1', ['c_login_account' => 'admin_user'], ['i_id_user' => 'x'], new \stdClass(), 1.5] as $stored) {
            $label = var_export($stored, true);
            $result = $this->authenticateWith($stored);

            $this->assertFalse($result->isValid(), $label);
            $this->assertFalse($this->session->check('Auth'), $label . ': セッションに残っている');
        }
    }

    public function testIdOfMissingUser_isUnauthenticated(): void
    {
        $this->assertFalse($this->authenticateWith(99999)->isValid());
        $this->assertFalse($this->authenticateWith(0)->isValid());
    }

    public function testEmptySession_isUnauthenticated(): void
    {
        $result = $this->authenticator->authenticate($this->request);

        $this->assertSame(Result::FAILURE_IDENTITY_NOT_FOUND, $result->getStatus());
    }

    /**
     * ログイン時にセッションへ保存するのは利用者 ID だけ（Entity を保存しない）。
     */
    public function testPersistIdentity_storesUserIdOnly(): void
    {
        $user = $this->getTableLocator()->get('MUserInfo')->get(1);

        $this->authenticator->persistIdentity($this->request, new Response(), $user);

        $this->assertSame(1, $this->session->read('Auth'));
        $this->assertStringNotContainsString('O:', serialize($this->session->read()), 'セッションにオブジェクトが入っている');
    }

    public function testClearIdentity_removesUser(): void
    {
        $this->session->write('Auth', 1);

        $this->authenticator->clearIdentity($this->request, new Response());

        $this->assertFalse($this->session->check('Auth'));
    }
}
