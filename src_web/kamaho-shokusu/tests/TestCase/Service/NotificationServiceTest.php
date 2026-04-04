<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\NotificationService;
use Cake\I18n\DateTime;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * NotificationService のテスト
 *
 * 通知の作成・既読・カウントの各ロジックを検証する。
 */
class NotificationServiceTest extends TestCase
{
    protected array $fixtures = [
        'app.TNotification',
        'app.MUserInfo',
        'app.MRoomInfo',
        'app.TIndividualReservationInfo',
    ];

    private NotificationService $service;

    /** @var \Cake\ORM\Table */
    private $notificationTable;

    public function setUp(): void
    {
        parent::setUp();
        $this->service = new NotificationService();
        $this->notificationTable = TableRegistry::getTableLocator()->get(
            'TNotification',
            ['table' => 't_notification']
        );
    }

    // ---------------------------------------------------------------------------
    // getUnreadCount()
    // ---------------------------------------------------------------------------

    public function testGetUnreadCountReturnsCorrectCount(): void
    {
        // フィクスチャ: ユーザー1に未読1件
        $count = $this->service->getUnreadCount(1);

        $this->assertSame(1, $count);
    }

    public function testGetUnreadCountReturnsZeroForUnknownUser(): void
    {
        $count = $this->service->getUnreadCount(9999);

        $this->assertSame(0, $count);
    }

    public function testGetUnreadCountReturnsZeroForInvalidUserId(): void
    {
        $count = $this->service->getUnreadCount(0);

        $this->assertSame(0, $count);
    }

    // ---------------------------------------------------------------------------
    // getRecentNotifications()
    // ---------------------------------------------------------------------------

    public function testGetRecentNotificationsReturnsNotifications(): void
    {
        $notifications = $this->service->getRecentNotifications(1, 5);

        $this->assertCount(2, $notifications);
    }

    public function testGetRecentNotificationsLimitsResults(): void
    {
        $notifications = $this->service->getRecentNotifications(1, 1);

        $this->assertCount(1, $notifications);
    }

    public function testGetRecentNotificationsReturnsEmptyArrayForInvalidUserId(): void
    {
        $notifications = $this->service->getRecentNotifications(0);

        $this->assertSame([], $notifications);
    }

    // ---------------------------------------------------------------------------
    // getNotifications()
    // ---------------------------------------------------------------------------

    public function testGetNotificationsReturnsAllNotificationsForUser(): void
    {
        $notifications = $this->service->getNotifications(1);

        $this->assertCount(2, $notifications);
    }

    public function testGetNotificationsReturnsEmptyForNonExistentUser(): void
    {
        $notifications = $this->service->getNotifications(9999);

        $this->assertSame([], $notifications);
    }

    // ---------------------------------------------------------------------------
    // markAsRead()
    // ---------------------------------------------------------------------------

    public function testMarkAsReadUpdatesIsReadFlag(): void
    {
        $updated = $this->service->markAsRead(1, [1]);

        $this->assertSame(1, $updated);

        $record = $this->notificationTable->get(1);
        $this->assertSame(1, (int)$record->i_is_read);
    }

    public function testMarkAsReadIgnoresOtherUsersNotifications(): void
    {
        // ユーザー2の通知IDとして存在しないIDを指定
        $updated = $this->service->markAsRead(2, [1]);

        // ユーザー1のID=1はユーザー2では更新されない
        $this->assertSame(0, $updated);
    }

    public function testMarkAsReadReturnsZeroForEmptyIds(): void
    {
        $updated = $this->service->markAsRead(1, []);

        $this->assertSame(0, $updated);
    }

    public function testMarkAsReadReturnsZeroForInvalidUserId(): void
    {
        $updated = $this->service->markAsRead(0, [1]);

        $this->assertSame(0, $updated);
    }

    // ---------------------------------------------------------------------------
    // markAllAsRead()
    // ---------------------------------------------------------------------------

    public function testMarkAllAsReadUpdatesAllUnreadNotifications(): void
    {
        $updated = $this->service->markAllAsRead(1);

        $this->assertSame(1, $updated); // フィクスチャで未読は1件

        $unread = $this->notificationTable->find()
            ->where(['i_id_user' => 1, 'i_is_read' => 0])
            ->count();
        $this->assertSame(0, $unread);
    }

    public function testMarkAllAsReadReturnsZeroForInvalidUserId(): void
    {
        $updated = $this->service->markAllAsRead(0);

        $this->assertSame(0, $updated);
    }
}
