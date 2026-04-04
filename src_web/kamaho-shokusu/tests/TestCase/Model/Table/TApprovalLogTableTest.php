<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\TApprovalLogTable;
use Cake\TestSuite\TestCase;

class TApprovalLogTableTest extends TestCase
{
    protected TApprovalLogTable $TApprovalLog;

    protected array $fixtures = [
        'app.TApprovalLog',
        'app.MUserInfo',
        'app.MRoomInfo',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $config = $this->getTableLocator()->exists('TApprovalLog') ? [] : ['className' => TApprovalLogTable::class];
        $this->TApprovalLog = $this->getTableLocator()->get('TApprovalLog', $config);
    }

    protected function tearDown(): void
    {
        unset($this->TApprovalLog);
        parent::tearDown();
    }

    // ---------------------------------------------------------------------------
    // validationDefault()
    // ---------------------------------------------------------------------------

    public function testValidationRequiredFieldsPass(): void
    {
        $entity = $this->TApprovalLog->newEntity([
            'i_id_user'          => 1,
            'd_reservation_date' => '2026-05-01',
            'i_id_room'          => 1,
            'i_reservation_type' => 1,
            'i_approval_status'  => 1,
            'i_approver_id'      => 2,
            'dt_create'          => '2026-05-01 10:00:00',
        ]);

        $this->assertEmpty($entity->getErrors(), '必須フィールドが全て揃っていればエラーなし');
    }

    public function testValidationFailsWhenUserIdMissing(): void
    {
        $entity = $this->TApprovalLog->newEntity([
            'd_reservation_date' => '2026-05-01',
            'i_id_room'          => 1,
            'i_reservation_type' => 1,
            'i_approval_status'  => 1,
            'i_approver_id'      => 2,
            'dt_create'          => '2026-05-01 10:00:00',
        ]);

        $this->assertArrayHasKey('i_id_user', $entity->getErrors());
    }

    public function testValidationFailsWhenApprovalStatusIsInvalid(): void
    {
        $entity = $this->TApprovalLog->newEntity([
            'i_id_user'          => 1,
            'd_reservation_date' => '2026-05-01',
            'i_id_room'          => 1,
            'i_reservation_type' => 1,
            'i_approval_status'  => 99, // 不正な値 (有効値: 1,2,3)
            'i_approver_id'      => 2,
            'dt_create'          => '2026-05-01 10:00:00',
        ]);

        $this->assertArrayHasKey('i_approval_status', $entity->getErrors());
    }

    public function testValidationRejectReasonAllowsNull(): void
    {
        $entity = $this->TApprovalLog->newEntity([
            'i_id_user'          => 1,
            'd_reservation_date' => '2026-05-01',
            'i_id_room'          => 1,
            'i_reservation_type' => 1,
            'i_approval_status'  => 2,
            'i_approver_id'      => 2,
            'c_reject_reason'    => null,
            'dt_create'          => '2026-05-01 10:00:00',
        ]);

        $this->assertArrayNotHasKey('c_reject_reason', $entity->getErrors());
    }

    public function testValidationRejectReasonTooLong(): void
    {
        $entity = $this->TApprovalLog->newEntity([
            'i_id_user'          => 1,
            'd_reservation_date' => '2026-05-01',
            'i_id_room'          => 1,
            'i_reservation_type' => 1,
            'i_approval_status'  => 3,
            'i_approver_id'      => 2,
            'c_reject_reason'    => str_repeat('あ', 256), // 256文字超え
            'dt_create'          => '2026-05-01 10:00:00',
        ]);

        $this->assertArrayHasKey('c_reject_reason', $entity->getErrors());
    }

    // ---------------------------------------------------------------------------
    // フィクスチャ読み込み確認
    // ---------------------------------------------------------------------------

    public function testFixtureRecordIsLoaded(): void
    {
        $count = $this->TApprovalLog->find()->count();

        $this->assertSame(1, $count);
    }
}
