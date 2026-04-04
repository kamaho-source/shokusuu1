<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\TNotificationTable;
use Cake\TestSuite\TestCase;

class TNotificationTableTest extends TestCase
{
    protected TNotificationTable $TNotification;

    protected array $fixtures = [
        'app.TNotification',
        'app.MUserInfo',
    ];

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

    // ---------------------------------------------------------------------------
    // validationDefault()
    // ---------------------------------------------------------------------------

    public function testValidationRequiredFieldsPass(): void
    {
        $entity = $this->TNotification->newEntity([
            'i_id_user'           => 1,
            'c_notification_type' => 'approval_rejected',
            'c_title'             => 'テストタイトル',
            'c_message'           => 'テストメッセージ',
            'dt_create'           => '2026-05-01 10:00:00',
        ]);

        $this->assertEmpty($entity->getErrors());
    }

    public function testValidationFailsWhenUserIdMissing(): void
    {
        $entity = $this->TNotification->newEntity([
            'c_notification_type' => 'approval_rejected',
            'c_title'             => 'タイトル',
            'c_message'           => 'メッセージ',
            'dt_create'           => '2026-05-01 10:00:00',
        ]);

        $this->assertArrayHasKey('i_id_user', $entity->getErrors());
    }

    public function testValidationFailsWhenTitleMissing(): void
    {
        $entity = $this->TNotification->newEntity([
            'i_id_user'           => 1,
            'c_notification_type' => 'approval_rejected',
            'c_message'           => 'メッセージ',
            'dt_create'           => '2026-05-01 10:00:00',
        ]);

        $this->assertArrayHasKey('c_title', $entity->getErrors());
    }

    public function testValidationFailsWhenIsReadIsInvalid(): void
    {
        $entity = $this->TNotification->newEntity([
            'i_id_user'           => 1,
            'c_notification_type' => 'approval_rejected',
            'c_title'             => 'タイトル',
            'c_message'           => 'メッセージ',
            'i_is_read'           => 2, // 有効値は 0 か 1
            'dt_create'           => '2026-05-01 10:00:00',
        ]);

        $this->assertArrayHasKey('i_is_read', $entity->getErrors());
    }

    public function testValidationLinkIsOptional(): void
    {
        $entity = $this->TNotification->newEntity([
            'i_id_user'           => 1,
            'c_notification_type' => 'approval_rejected',
            'c_title'             => 'タイトル',
            'c_message'           => 'メッセージ',
            'c_link'              => null,
            'dt_create'           => '2026-05-01 10:00:00',
        ]);

        $this->assertArrayNotHasKey('c_link', $entity->getErrors());
    }

    // ---------------------------------------------------------------------------
    // フィクスチャ読み込み確認
    // ---------------------------------------------------------------------------

    public function testFixtureRecordsAreLoaded(): void
    {
        $count = $this->TNotification->find()->count();

        $this->assertSame(2, $count);
    }

    public function testUnreadCountIsCorrect(): void
    {
        $unread = $this->TNotification->find()
            ->where(['i_id_user' => 1, 'i_is_read' => 0])
            ->count();

        $this->assertSame(1, $unread);
    }
}
