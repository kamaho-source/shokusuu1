<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Authentication\AuthenticationService;
use Authentication\PasswordHasher\DefaultPasswordHasher;
use Cake\Datasource\ConnectionManager;
use Cake\Event\EventInterface;
use Cake\Event\EventManager;
use Cake\Http\MiddlewareQueue;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * #989 不具合5〜7 の再現テスト（利用者のログインID重複チェック・認証結果 null・無効アカウントの判定）。
 *
 * ユーザー: 1=管理者 / 2=職員 / 3=子ども(i_user_level=1。登録の権限なし)
 */
class MUserInfoBugFix989Test extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TAuditLog',
    ];

    // ---------------------------------------------------------------
    // 不具合5：ログインID重複チェック API
    // ---------------------------------------------------------------

    /**
     * @return array<string, bool>
     */
    private function json(): array
    {
        $decoded = json_decode((string)$this->_response->getBody(), true);

        return is_array($decoded) ? $decoded : [];
    }

    public function testCheckUniqueLoginId_usedAccount_isNotUnique(): void
    {
        $this->session(['Auth' => 2]);

        $this->get('/MUserInfo/check-unique-login-id?c_login_account=admin_user');

        $this->assertResponseOk();
        $this->assertContentType('application/json');
        $this->assertSame(['unique' => false], $this->json());
    }

    public function testCheckUniqueLoginId_unusedAccount_isUnique(): void
    {
        $this->session(['Auth' => 2]);

        $this->get('/MUserInfo/check-unique-login-id?c_login_account=nobody_uses_this');

        $this->assertResponseOk();
        $this->assertSame(['unique' => true], $this->json());
    }

    /** 削除済みの人のログインIDも使用済み（送信時の CreateUserUseCase と同じ判定） */
    public function testCheckUniqueLoginId_deletedUsersAccount_isNotUnique(): void
    {
        $this->session(['Auth' => 2]);

        $this->get('/MUserInfo/check-unique-login-id?c_login_account=deleted_user');

        $this->assertSame(['unique' => false], $this->json());
    }

    public function testCheckUniqueLoginId_emptyOrArray_isBadRequest(): void
    {
        $this->session(['Auth' => 2]);
        $this->get('/MUserInfo/check-unique-login-id?c_login_account=');
        $this->assertResponseCode(400);

        $this->session(['Auth' => 2]);
        $this->get('/MUserInfo/check-unique-login-id');
        $this->assertResponseCode(400);

        $this->session(['Auth' => 2]);
        $this->get('/MUserInfo/check-unique-login-id?c_login_account[]=admin_user');
        $this->assertResponseCode(400);
    }

    /** 登録の権限がない人（子ども）は使えない */
    public function testCheckUniqueLoginId_withoutAddPermission_isForbidden(): void
    {
        $this->session(['Auth' => 3]);

        $this->get('/MUserInfo/check-unique-login-id?c_login_account=admin_user');

        $this->assertResponseCode(403);
        $this->assertResponseNotContains('unique');
    }

    /** 未ログインはログイン画面へ（中身は返さない） */
    public function testCheckUniqueLoginId_unauthenticated_isRedirectedToLogin(): void
    {
        $this->get('/MUserInfo/check-unique-login-id?c_login_account=admin_user');

        $this->assertRedirectContains('/MUserInfo/login');
        $this->assertResponseNotContains('unique');
    }

    public function testCheckUniqueLoginId_postIsNotAllowed(): void
    {
        $this->session(['Auth' => 2]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();

        $this->post('/MUserInfo/check-unique-login-id', ['c_login_account' => 'admin_user']);

        $this->assertResponseCode(405);
    }

    /** 登録画面の呼び出し先が BASE_PATH 付きの実在する URL になっている */
    public function testAddPage_callsCheckUniqueLoginIdWithBasePath(): void
    {
        $this->session(['Auth' => 1]);

        $this->get('/MUserInfo/add');

        $this->assertResponseOk();
        $this->assertResponseNotContains("fetch('/m-user-info/check-unique-login-id'");
        $this->assertResponseContains("BASE_PATH + '/MUserInfo/check-unique-login-id?c_login_account='");
    }

    // ---------------------------------------------------------------
    // 不具合6：認証結果が null のときのログイン失敗・ログアウト
    // ---------------------------------------------------------------

    /**
     * 認証ミドルウェアの後で、request の authentication を「まだ authenticate() していない」サービスに差し替える。
     * AuthenticationComponent::getResult() は ?ResultInterface なので、この状態では null を返す。
     */
    private function replaceAuthenticationServiceWithUnauthenticatedOne(): void
    {
        $this->middlewareListener = function (EventInterface $event, MiddlewareQueue $queue): void {
            $queue->add(static function (ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
                return $handler->handle($request->withAttribute('authentication', new AuthenticationService()));
            });
        };
        EventManager::instance()->on('Server.buildMiddleware', $this->middlewareListener);
    }

    /** @var callable|null */
    private $middlewareListener = null;

    protected function tearDown(): void
    {
        if ($this->middlewareListener !== null) {
            EventManager::instance()->off('Server.buildMiddleware', $this->middlewareListener);
            $this->middlewareListener = null;
        }
        parent::tearDown();
    }

    private function failedLoginCount(): int
    {
        return $this->getTableLocator()->get('TAuditLog')->find()->where(['c_action' => 'user_login_failed'])->count();
    }

    public function testLoginPost_whenResultIsNull_showsFailureInsteadOf500(): void
    {
        $this->replaceAuthenticationServiceWithUnauthenticatedOne();
        $this->enableCsrfToken();
        $failedBefore = $this->failedLoginCount();

        $this->post('/MUserInfo/login', ['c_login_account' => 'admin_user', 'c_login_passwd' => 'whatever']);

        $this->assertResponseOk();
        $this->assertResponseContains('ユーザー名またはパスワードが正しくありません。');
        $this->assertSame($failedBefore + 1, $this->failedLoginCount());
    }

    public function testLogout_whenResultIsNull_redirectsToLoginInsteadOf500(): void
    {
        $this->replaceAuthenticationServiceWithUnauthenticatedOne();
        $this->session(['Auth' => 2]);

        $this->get('/MUserInfo/logout');

        $this->assertRedirectContains('/MUserInfo/login');
    }

    // ---------------------------------------------------------------
    // 不具合7：無効アカウントの判定（値が文字列で来ても無効と判定する）
    // ---------------------------------------------------------------

    /**
     * @dataProvider disabledFlagProvider
     */
    public function testLogin_disabledFlagNotInt_isRejected(string $column, string $type): void
    {
        $db = ConnectionManager::get('test');
        $db->update('m_user_info', [
            'c_login_passwd' => (new DefaultPasswordHasher())->hash('correct-pass'),
            $column => 1,
        ], ['i_id_user' => 2]);
        // カラムの型によっては整数でなく文字列（decimal）や真偽値（MySQL の tinyint(1) は boolean になる）で返る
        $this->getTableLocator()->get('MUserInfo')->getSchema()->setColumnType($column, $type);
        $this->enableCsrfToken();

        $this->post('/MUserInfo/login', ['c_login_account' => 'staff_user', 'c_login_passwd' => 'correct-pass']);

        $this->assertRedirectContains('/MUserInfo/login');
        $this->assertFlashMessage('このアカウントは無効化されています。');
        $this->assertSessionNotHasKey('Auth');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function disabledFlagProvider(): array
    {
        return [
            'i_enable が文字列 "1"'    => ['i_enable', 'decimal'],
            'i_del_flag が文字列 "1"'  => ['i_del_flag', 'decimal'],
            'i_enable が true'         => ['i_enable', 'boolean'],
            'i_del_flag が true'       => ['i_del_flag', 'boolean'],
        ];
    }
}
