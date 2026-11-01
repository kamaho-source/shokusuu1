<?php
declare(strict_types=1);

namespace App\Test\TestCase\Infrastructure\Persistence\Table;

use App\Infrastructure\Persistence\Table\TApprovalLogTable;
use Cake\TestSuite\TestCase;

/**
 * App\Infrastructure\Persistence\Table\TApprovalLogTable Test Case
 */
class TApprovalLogTableTest extends TestCase
{
    protected array $fixtures = [
        'app.TApprovalLog',
    ];

    protected TApprovalLogTable $TApprovalLog;

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

    /**
     * @uses \App\Infrastructure\Persistence\Table\TApprovalLogTable::validationDefault()
     */
    public function testValidationDefault(): void
    {
        $valid = $this->TApprovalLog->newEntity([
            'i_id_user' => 1,
            'd_reservation_date' => '2026-09-01',
            'i_id_room' => 1,
            'i_reservation_type' => 1,
            'i_approval_status' => 1,
            'i_approver_id' => 2,
            'dt_create' => '2026-08-25 10:00:00',
        ]);
        $this->assertEmpty($valid->getErrors());

        $invalid = $this->TApprovalLog->newEntity([
            'i_approval_status' => 99,
        ]);
        $this->assertArrayHasKey('i_id_user', $invalid->getErrors());
        $this->assertArrayHasKey('i_approval_status', $invalid->getErrors());
    }
}
