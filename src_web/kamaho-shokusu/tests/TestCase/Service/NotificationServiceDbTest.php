<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\NotificationService;
use Cake\I18n\DateTime;
use Cake\ORM\Table;
use Cake\TestSuite\TestCase;

/**
 * NotificationService の DB を使うテスト（差し戻し通知の生成・取得・既読化）。
 */
class NotificationServiceDbTest extends TestCase
{
    protected array $fixtures = [
        'app.TIndividualReservationInfo',
        'app.MRoomInfo',
        'app.MUserInfo',
    ];

    private NotificationService $service;
    private Table $notifications;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new NotificationService();
        $this->notifications = $this->getTableLocator()->get('TNotification');
        $this->notifications->deleteAll([]);
    }

    protected function tearDown(): void
    {
        $this->notifications->deleteAll([]);
        parent::tearDown();
    }

    private function addReservation(int $userId, string $date, int $type): void
    {
        $table = $this->getTableLocator()->get('TIndividualReservationInfo');
        $table->saveOrFail($table->newEntity([
            'i_id_user' => $userId,
            'd_reservation_date' => $date,
            'i_reservation_type' => $type,
            'i_id_room' => 1,
            'eat_flag' => 1,
            'i_approval_status' => 3,
            'dt_create' => '2024-09-01 00:00:00',
            'c_create_user' => 't',
            'dt_update' => '2024-09-01 00:00:00',
            'c_update_user' => 't',
        ]));
    }

    private function addNotification(int $userId, string $createdAt, int $isRead = 0): int
    {
        $entity = $this->notifications->newEntity([
            'i_id_user' => $userId,
            'c_notification_type' => 'x',
            'c_title' => 't',
            'c_message' => 'm',
            'i_is_read' => $isRead,
            'dt_create' => $createdAt,
        ]);
        $this->notifications->saveOrFail($entity);

        return (int)$entity->i_id_notification;
    }

    public function testCreateRejectionNotifications_emptyKeys_createsNothing(): void
    {
        $this->service->createRejectionNotifications([], 1, '理由');
        $this->assertSame(0, $this->notifications->find()->count());
    }

    public function testCreateRejectionNotifications_noMatchingRows_createsNothing(): void
    {
        $this->service->createRejectionNotifications([
            ['i_id_user' => 99, 'd_reservation_date' => '2030-01-01', 'i_id_room' => 1, 'i_reservation_type' => 1],
        ], 1, null);
        $this->assertSame(0, $this->notifications->find()->count());
    }

    public function testCreateRejectionNotifications_groupsPerUserWithDeepLinkAndReason(): void
    {
        // user 1: 5件(「ほか2件」になる)、user 2: 1件
        $keys = [];
        foreach ([['2024-09-11', 1], ['2024-09-11', 3], ['2024-09-12', 1], ['2024-09-12', 3]] as [$date, $type]) {
            $this->addReservation(1, $date, $type);
            $keys[] = ['i_id_user' => 1, 'd_reservation_date' => $date, 'i_id_room' => 1, 'i_reservation_type' => $type];
        }
        // フィクスチャの既存予約（user1 / 2024-09-07 / 朝）
        $keys[] = ['i_id_user' => 1, 'd_reservation_date' => '2024-09-07', 'i_id_room' => 1, 'i_reservation_type' => 1];
        $this->addReservation(2, '2024-09-18', 2);
        $keys[] = ['i_id_user' => 2, 'd_reservation_date' => '2024-09-18', 'i_id_room' => 1, 'i_reservation_type' => 2];

        $at = new DateTime('2024-10-01 12:00:00');
        $this->service->createRejectionNotifications($keys, 4, '  人数が不明  ', $at);

        $rows = $this->notifications->find()->orderBy(['i_id_user' => 'ASC'])->all()->toArray();
        $this->assertCount(2, $rows);

        $u1 = $rows[0];
        $this->assertSame(1, (int)$u1->i_id_user);
        $this->assertSame(NotificationService::TYPE_APPROVAL_REJECTED, $u1->c_notification_type);
        $this->assertSame('予約が差し戻されました', $u1->c_title);
        $this->assertStringContainsString('システム管理者 により予約 5件 が差し戻されました', $u1->c_message);
        $this->assertStringContainsString('ほか2件', $u1->c_message);
        $this->assertStringContainsString('/ 理由: 人数が不明', $u1->c_message);
        // 最も早い日付(2024-09-07 土) の週の月曜
        $this->assertSame('/TReservationInfo/my-actual-meal?week=2024-09-02', $u1->c_link);
        $this->assertSame(0, (int)$u1->i_is_read);

        $u2 = $rows[1];
        $this->assertSame(2, (int)$u2->i_id_user);
        $this->assertStringContainsString('2024-09-18', $u2->c_message);
        $this->assertStringContainsString('昼', $u2->c_message);
        $this->assertStringNotContainsString('ほか', $u2->c_message);
        $this->assertSame('/TReservationInfo/my-actual-meal?week=2024-09-16', $u2->c_link);
    }

    public function testCreateRejectionNotifications_blankReasonAndUnknownApprover(): void
    {
        $this->addReservation(1, '2024-09-11', 1);
        $this->service->createRejectionNotifications([
            ['i_id_user' => 1, 'd_reservation_date' => '2024-09-11', 'i_id_room' => 1, 'i_reservation_type' => 1],
        ], 777, '   ');

        $row = $this->notifications->find()->firstOrFail();
        $this->assertStringContainsString('777 により', $row->c_message);
        $this->assertStringNotContainsString('理由', $row->c_message);
    }

    public function testCreateRejectionNotifications_truncatesLongMessage(): void
    {
        $this->addReservation(1, '2024-09-11', 1);
        $this->service->createRejectionNotifications([
            ['i_id_user' => 1, 'd_reservation_date' => '2024-09-11', 'i_id_room' => 1, 'i_reservation_type' => 1],
        ], 4, str_repeat('あ', 400));

        $row = $this->notifications->find()->firstOrFail();
        $this->assertLessThanOrEqual(255, mb_strwidth($row->c_message));
        $this->assertStringEndsWith('...', $row->c_message);
    }

    public function testGetUnreadCount_countsOnlyOwnUnread(): void
    {
        $this->addNotification(1, '2024-10-01 10:00:00');
        $this->addNotification(1, '2024-10-01 11:00:00', 1);
        $this->addNotification(2, '2024-10-01 12:00:00');

        $this->assertSame(1, $this->service->getUnreadCount(1));
        $this->assertSame(1, $this->service->getUnreadCount(2));
        $this->assertSame(0, $this->service->getUnreadCount(3));
    }

    public function testGetRecentNotificationsAndGetNotifications_orderedAndLimited(): void
    {
        $this->assertSame([], $this->service->getRecentNotifications(0));
        $this->assertSame([], $this->service->getNotifications(-1));

        $old = $this->addNotification(1, '2024-10-01 10:00:00');
        $mid = $this->addNotification(1, '2024-10-02 10:00:00');
        $new = $this->addNotification(1, '2024-10-03 10:00:00');
        $this->addNotification(2, '2024-10-04 10:00:00');

        $recent = $this->service->getRecentNotifications(1, 2);
        $this->assertSame([$new, $mid], array_map(fn($n) => (int)$n->i_id_notification, $recent));

        $all = $this->service->getNotifications(1);
        $this->assertSame([$new, $mid, $old], array_map(fn($n) => (int)$n->i_id_notification, $all));
        $this->assertCount(1, $this->service->getNotifications(1, 1));
    }

    public function testMarkAsRead_updatesOnlyOwnTargetedRows(): void
    {
        $a = $this->addNotification(1, '2024-10-01 10:00:00');
        $b = $this->addNotification(1, '2024-10-02 10:00:00');
        $other = $this->addNotification(2, '2024-10-03 10:00:00');

        $count = $this->service->markAsRead(1, [$a, (string)$a, $other]);

        $this->assertSame(1, $count);
        $this->assertSame(1, (int)$this->notifications->get($a)->i_is_read);
        $this->assertNotNull($this->notifications->get($a)->dt_read);
        $this->assertSame(0, (int)$this->notifications->get($b)->i_is_read);
        $this->assertSame(0, (int)$this->notifications->get($other)->i_is_read);
    }

    public function testMarkAllAsRead_marksOnlyOwnUnread(): void
    {
        $this->assertSame(0, $this->service->markAllAsRead(0));
        $a = $this->addNotification(1, '2024-10-01 10:00:00');
        $b = $this->addNotification(1, '2024-10-02 10:00:00', 1);
        $other = $this->addNotification(2, '2024-10-03 10:00:00');

        $this->assertSame(1, $this->service->markAllAsRead(1));
        $this->assertSame(1, (int)$this->notifications->get($a)->i_is_read);
        $this->assertSame(1, (int)$this->notifications->get($b)->i_is_read);
        $this->assertSame(0, (int)$this->notifications->get($other)->i_is_read);
        $this->assertSame(0, $this->service->getUnreadCount(1));
    }
}
