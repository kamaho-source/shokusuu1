<?php
declare(strict_types=1);

namespace App\Test\TestCase\Config;

use Cake\Core\Configure;
use Cake\Datasource\ConnectionManager;
use Cake\TestSuite\TestCase;

/**
 * タイムゾーン設定（日本時間）の回帰テスト。
 *
 * アプリ・PHP・DB 接続のタイムゾーンが食い違うと、日付の境界（0〜9時）でずれが出る。
 */
class TimezoneConfigTest extends TestCase
{
    public function testPhpDefaultTimezoneIsTokyo(): void
    {
        $this->assertSame('Asia/Tokyo', date_default_timezone_get());
    }

    public function testAppDefaultTimezoneIsTokyo(): void
    {
        $this->assertSame('Asia/Tokyo', Configure::read('App.defaultTimezone'));
    }

    public function testDatabaseConnectionTimezoneIsJstOffset(): void
    {
        foreach (['default', 'test'] as $name) {
            $config = ConnectionManager::getConfig($name);
            $this->assertSame('+09:00', $config['timezone'] ?? null, "{$name} の接続タイムゾーン");
        }
    }
}
