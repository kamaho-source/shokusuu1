<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Controller\AppController;
use Cake\Http\ServerRequest;
use Cake\TestSuite\TestCase;

/**
 * AppController::getClientIp が、監査ログに残す接続元 IP を偽れないことを検証する。
 *
 * 本番の nginx は X-Real-IP を接続元（$remote_addr）で上書きし、X-Forwarded-For はクライアントが送った値の後ろに追記する。
 * そのため X-Forwarded-For の先頭はクライアントが自由に書ける（2026-11-07 の監査で見つかった不具合）。
 */
class AppControllerClientIpTest extends TestCase
{
    /**
     * @param array<string, string> $server
     */
    private function clientIp(array $server): string
    {
        $controller = new class (new ServerRequest(['environment' => $server + ['REMOTE_ADDR' => '127.0.0.1']])) extends AppController {
            public function initialize(): void
            {
                // コンポーネント・サービス初期化は検証対象外のためスキップ
            }
        };

        return (string)(new \ReflectionMethod($controller, 'getClientIp'))->invoke($controller);
    }

    public function testSpoofedForwardedFor_isIgnoredWhenProxySetsRealIp(): void
    {
        // クライアントが X-Forwarded-For: 1.2.3.4 を送ると、nginx を通った後は「1.2.3.4, 実際の接続元」になる
        $ip = $this->clientIp(['HTTP_X_FORWARDED_FOR' => '1.2.3.4, 203.0.113.9', 'HTTP_X_REAL_IP' => '203.0.113.9']);

        $this->assertSame('203.0.113.9', $ip);
    }

    public function testWithoutRealIp_usesTheEntryAddedByTheNearestProxy(): void
    {
        $this->assertSame('203.0.113.9', $this->clientIp(['HTTP_X_FORWARDED_FOR' => '1.2.3.4, 203.0.113.9']));
    }

    public function testInvalidHeaders_fallBackToRemoteAddr(): void
    {
        $this->assertSame('127.0.0.1', $this->clientIp(['HTTP_X_FORWARDED_FOR' => 'evil', 'HTTP_X_REAL_IP' => 'evil']));
        $this->assertSame('127.0.0.1', $this->clientIp([]));
    }
}
