<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Cache\Cache;
use Cake\Core\Configure;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * ログイン試行のIPベースthrottle（SHOKUSU-32 #1）のテスト。
 *
 * @uses \App\Controller\MUserInfoController::login()
 */
class MUserInfoLoginThrottleTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * Fixtures
     *
     * @var list<string>
     */
    protected array $fixtures = [
        'app.MUserInfo',
        'app.TAuditLog',
    ];

    public function setUp(): void
    {
        parent::setUp();
        Configure::write('debug', true);
        Cache::clear('default');
        $this->enableCsrfToken();
        $this->enableRetainFlashMessages();
    }

    public function tearDown(): void
    {
        Cache::clear('default');
        Configure::write('debug', false);
        parent::tearDown();
    }

    /**
     * 失敗が上限に達すると11回目以降は HTTP 429 になる。
     *
     * @return void
     */
    public function testBlocksAfterTenFailures(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postLoginFailure();
            $this->assertResponseCode(200, "{$i}回目の失敗で遮断されてはいけない");
        }

        $this->postLoginFailure();
        $this->assertResponseCode(429);
    }

    /**
     * 遮断中でもカウンタを消せば再びログイン画面が表示される（TTL経過相当）。
     *
     * @return void
     */
    public function testCounterResetLiftsTheBlock(): void
    {
        for ($i = 0; $i < 10; $i++) {
            $this->postLoginFailure();
        }
        $this->postLoginFailure();
        $this->assertResponseCode(429);

        Cache::clear('default');

        $this->postLoginFailure();
        $this->assertResponseCode(200);
    }

    /**
     * インターネットから直接届いたリクエストでは、偽装した X-Forwarded-For を
     * 採用せず、実際の接続元アドレスを記録する。
     *
     * これが崩れると、ヘッダを差し替えるだけで回数制限を回避でき、
     * 監査ログのIPも攻撃者の任意の値に汚染される。
     *
     * @return void
     */
    public function testSpoofedForwardedForIsIgnoredFromPublicPeer(): void
    {
        $this->postLoginFailure('203.0.113.5', '198.51.100.77');

        $this->assertSame('203.0.113.5', $this->lastAuditIp());
    }

    /**
     * 自前のリバースプロキシ経由（接続元がプライベートアドレス）の場合は
     * X-Forwarded-For が付与した実クライアントIPを採用する。
     *
     * @return void
     */
    public function testForwardedForIsHonouredBehindReverseProxy(): void
    {
        $this->postLoginFailure('172.17.0.1', '203.0.113.10');

        $this->assertSame('203.0.113.10', $this->lastAuditIp());
    }

    /**
     * IPアドレスとして不正な X-Forwarded-For は採用しない。
     *
     * 長大な文字列を通すと監査ログの保存が失敗し、記録が失われるため。
     *
     * @return void
     */
    public function testInvalidForwardedForFallsBackToRemoteAddr(): void
    {
        $this->postLoginFailure('172.17.0.1', str_repeat('A', 60));

        $this->assertSame('172.17.0.1', $this->lastAuditIp());
    }

    /**
     * 直近の監査ログに記録されたIPアドレスを返す。
     *
     * @return string|null
     */
    private function lastAuditIp(): ?string
    {
        $row = TableRegistry::getTableLocator()->get('TAuditLog')
            ->find()->orderByDesc('i_id_audit')->first();

        return $row?->c_ip_address;
    }

    /**
     * 誤ったパスワードでログインPOSTを行う。
     *
     * @param string|null $remoteAddr   接続元アドレス（省略時は既定）
     * @param string|null $forwardedFor 付与する X-Forwarded-For（省略時は付けない）
     * @return void
     */
    private function postLoginFailure(?string $remoteAddr = null, ?string $forwardedFor = null): void
    {
        $config = [];
        if ($remoteAddr !== null) {
            $config['environment'] = ['REMOTE_ADDR' => $remoteAddr];
        }
        if ($forwardedFor !== null) {
            $config['headers'] = ['X-Forwarded-For' => $forwardedFor];
        }
        if ($config !== []) {
            $this->configRequest($config);
        }

        $this->post('/MUserInfo/login', [
            'c_login_account' => 'no_such_user',
            'c_login_passwd'  => 'wrong-password',
        ]);
    }
}
