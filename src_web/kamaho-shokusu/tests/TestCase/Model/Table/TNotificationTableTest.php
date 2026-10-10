<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\TNotificationTable;
use Cake\TestSuite\TestCase;

/**
 * App\Model\Table\TNotificationTable Test Case
 */
class TNotificationTableTest extends TestCase
{
    protected TNotificationTable $TNotification;

    protected function setUp(): void
    {
        parent::setUp();
        $config = $this->getTableLocator()->exists('TNotification') ? [] : ['className' => TNotificationTable::class];
        $this->TNotification = $this->getTableLocator()->get('TNotification', $config);
    }

    protected function tearDown(): void
    {
        unset($this->TNotification);

        parent::tearDown();
    }

    /**
     * @uses \App\Model\Table\TNotificationTable::validationDefault()
     */
    public function testValidationDefault(): void
    {
        $valid = $this->TNotification->newEntity([
            'i_id_user' => 1,
            'c_notification_type' => 'approval',
            'c_title' => '承認依頼',
            'c_message' => '予約の承認をお願いします。',
            'dt_create' => '2026-08-25 10:00:00',
        ]);
        $this->assertEmpty($valid->getErrors());

        $invalid = $this->TNotification->newEntity([
            'i_is_read' => 99,
        ]);
        $this->assertArrayHasKey('i_id_user', $invalid->getErrors());
        $this->assertArrayHasKey('c_notification_type', $invalid->getErrors());
        $this->assertArrayHasKey('c_title', $invalid->getErrors());
        $this->assertArrayHasKey('c_message', $invalid->getErrors());
        $this->assertArrayHasKey('i_is_read', $invalid->getErrors());
    }
}
