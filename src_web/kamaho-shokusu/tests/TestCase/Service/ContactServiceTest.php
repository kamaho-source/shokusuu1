<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ContactService;
use Cake\TestSuite\TestCase;

/**
 * ContactService テスト
 *
 * 管理者通知メールのスキップ判定（宛先未設定時は送らない）を検証する。
 * DB・メール送信に依存しない純粋メソッドのみを対象とする。
 */
class ContactServiceTest extends TestCase
{
    public function testShouldSkip_whenDefaultPlaceholder(): void
    {
        // デフォルト値(admin@localhost)のまま＝未設定扱い→スキップ
        $this->assertTrue(ContactService::shouldSkipAdminNotification('admin@localhost'));
    }

    public function testShouldSkip_whenEmpty(): void
    {
        $this->assertTrue(ContactService::shouldSkipAdminNotification(''));
        $this->assertTrue(ContactService::shouldSkipAdminNotification('   '));
    }

    public function testShouldNotSkip_whenValidAddress(): void
    {
        // 実際の宛先が設定されていれば送信する
        $this->assertFalse(ContactService::shouldSkipAdminNotification('admin@example.com'));
        $this->assertFalse(ContactService::shouldSkipAdminNotification('ops@kamaho-shokusu.jp'));
    }
}
