<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Cache\Cache;
use Cake\Core\Configure;
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
     * 誤ったパスワードでログインPOSTを行う。
     *
     * @return void
     */
    private function postLoginFailure(): void
    {
        $this->post('/MUserInfo/login', [
            'c_login_account' => 'no_such_user',
            'c_login_passwd'  => 'wrong-password',
        ]);
    }
}
