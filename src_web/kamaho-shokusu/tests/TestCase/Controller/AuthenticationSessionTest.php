<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Authentication\PasswordHasher\DefaultPasswordHasher;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * ログインのセッションに利用者 ID だけを保存することの結合テスト（Application の認証設定を通す）。
 */
class AuthenticationSessionTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TAuditLog',
    ];

    public function testLogin_storesOnlyUserIdInSession(): void
    {
        ConnectionManager::get('test')->update('m_user_info', ['c_login_passwd' => (new DefaultPasswordHasher())->hash('correct-pass')], ['i_id_user' => 1]);
        $this->enableCsrfToken();

        $this->post('/MUserInfo/login', ['c_login_account' => 'admin_user', 'c_login_passwd' => 'correct-pass']);

        $this->assertRedirectContains('/TReservationInfo');
        $this->assertSession(1, 'Auth');
    }

    /**
     * Entity を移す前にログインした人のセッション（旧クラス名で、今は復元できない Entity）でも、
     * 500 にならずにログインしたまま使え、セッションは利用者 ID だけに書き換わる。
     */
    public function testSessionWithLegacyEntityClass_staysLoggedInAndIsRewritten(): void
    {
        $user = $this->getTableLocator()->get('MUserInfo')->get(1);
        $legacy = 'App\\Model\\Entity\\NoSuchLegacyUserClass';
        $stored = unserialize((string)preg_replace('/^O:\d+:"[^"]+"/', 'O:' . strlen($legacy) . ':"' . $legacy . '"', serialize($user)));
        $this->assertInstanceOf(\__PHP_Incomplete_Class::class, $stored);
        $this->session(['Auth' => $stored]);

        $this->get('/TReservationInfo');

        $this->assertResponseOk();
        $this->assertSession(1, 'Auth');
    }

    /** 権限は DB から毎回読み直すので、DB で変えれば再ログインなしで反映される */
    public function testRoleChangeInDb_takesEffectWithoutRelogin(): void
    {
        ConnectionManager::get('test')->update('m_user_info', ['i_admin' => 0], ['i_id_user' => 2]);
        $this->session(['Auth' => 2]);

        $this->get('/MUserInfo/view/1');
        $this->assertRedirect(['controller' => 'MUserInfo', 'action' => 'index']);

        ConnectionManager::get('test')->update('m_user_info', ['i_admin' => 1], ['i_id_user' => 2]);
        $this->session(['Auth' => 2]);
        $this->get('/MUserInfo/view/1');
        $this->assertResponseOk();
    }

    /** ログイン中に無効化された人は、次のリクエストで締め出される */
    public function testUserDisabledWhileLoggedIn_isLoggedOut(): void
    {
        $this->session(['Auth' => 2]);
        ConnectionManager::get('test')->update('m_user_info', ['i_enable' => 1], ['i_id_user' => 2]);

        $this->get('/TReservationInfo');

        $this->assertRedirectContains('/MUserInfo/login');
        $this->assertSessionNotHasKey('Auth');
    }

    /** セッションの利用者が DB に居なければ、未ログインとしてログイン画面へ（500 にしない） */
    public function testSessionUserMissingInDb_redirectsToLogin(): void
    {
        $this->session(['Auth' => 99999]);

        $this->get('/TReservationInfo');

        $this->assertRedirectContains('/MUserInfo/login');
    }
}
