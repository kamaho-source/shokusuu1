<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\NotificationService;
use Cake\TestSuite\TestCase;

/**
 * NotificationService テスト。
 *
 * getUnreadCount・markAsRead・markAllAsRead・getRecentNotifications・getNotifications・createRejectionNotifications のガード条件を検証する。
 */
class NotificationServiceTest extends TestCase
{
    private NotificationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new NotificationService();
    }

    // ----------------------------------------------------------------
    // getUnreadCount — DB不要のガード条件
    // ----------------------------------------------------------------

    public function testGetUnreadCount_zeroUserId_returnsZero(): void
    {
        $result = $this->service->getUnreadCount(0);
        $this->assertSame(0, $result);
    }

    public function testGetUnreadCount_negativeUserId_returnsZero(): void
    {
        $result = $this->service->getUnreadCount(-5);
        $this->assertSame(0, $result);
    }

    // ----------------------------------------------------------------
    // markAsRead — DB不要のガード条件
    // ----------------------------------------------------------------

    public function testMarkAsRead_zeroUserId_returnsZero(): void
    {
        $result = $this->service->markAsRead(0, [1, 2, 3]);
        $this->assertSame(0, $result);
    }

    public function testMarkAsRead_emptyIds_returnsZero(): void
    {
        $result = $this->service->markAsRead(1, []);
        $this->assertSame(0, $result);
    }

    public function testMarkAsRead_zeroUserIdAndEmptyIds_returnsZero(): void
    {
        $result = $this->service->markAsRead(0, []);
        $this->assertSame(0, $result);
    }

    // ----------------------------------------------------------------
    // markAllAsRead — DB不要のガード条件
    // ----------------------------------------------------------------

    public function testMarkAllAsRead_zeroUserId_returnsZero(): void
    {
        $result = $this->service->markAllAsRead(0);
        $this->assertSame(0, $result);
    }

    public function testMarkAllAsRead_negativeUserId_returnsZero(): void
    {
        $result = $this->service->markAllAsRead(-1);
        $this->assertSame(0, $result);
    }

    // ----------------------------------------------------------------
    // getRecentNotifications / getNotifications — DB不要のガード条件
    // ----------------------------------------------------------------

    public function testGetRecentNotifications_zeroUserId_returnsEmptyArray(): void
    {
        $result = $this->service->getRecentNotifications(0);
        $this->assertSame([], $result);
    }

    public function testGetNotifications_zeroUserId_returnsEmptyArray(): void
    {
        $result = $this->service->getNotifications(0);
        $this->assertSame([], $result);
    }

    // ----------------------------------------------------------------
    // createRejectionNotifications — DB不要のガード条件（空キー）
    // ----------------------------------------------------------------

    public function testCreateRejectionNotifications_emptyKeys_returnsWithoutError(): void
    {
        // 空キーはガード条件で早期リターンするため例外が発生しないこと
        $this->service->createRejectionNotifications([], 1, null);
        $this->assertTrue(true); // ここに到達すれば成功
    }
}
