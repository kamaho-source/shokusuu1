<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\MRoomTransferScheduleTable;
use Cake\TestSuite\TestCase;

/**
 * App\Model\Table\MRoomTransferScheduleTable Test Case
 */
class MRoomTransferScheduleTableTest extends TestCase
{
    protected array $fixtures = [
        'app.MRoomTransferSchedule',
    ];

    protected MRoomTransferScheduleTable $MRoomTransferSchedule;

    protected function setUp(): void
    {
        parent::setUp();
        $config = $this->getTableLocator()->exists('MRoomTransferSchedule')
            ? []
            : ['className' => MRoomTransferScheduleTable::class];
        $this->MRoomTransferSchedule = $this->getTableLocator()->get('MRoomTransferSchedule', $config);
    }

    protected function tearDown(): void
    {
        unset($this->MRoomTransferSchedule);

        parent::tearDown();
    }

    /**
     * @uses \App\Model\Table\MRoomTransferScheduleTable::validationDefault()
     */
    public function testValidationDefault(): void
    {
        $valid = $this->MRoomTransferSchedule->newEntity([
            'i_id_user' => 1,
            'i_id_room_to' => 2,
            'd_effective_date' => '2026-09-01',
            'i_status' => MRoomTransferScheduleTable::STATUS_PENDING,
        ]);
        $this->assertEmpty($valid->getErrors());

        $invalid = $this->MRoomTransferSchedule->newEntity([
            'i_status' => 99,
        ]);
        $this->assertArrayHasKey('i_id_user', $invalid->getErrors());
        $this->assertArrayHasKey('i_id_room_to', $invalid->getErrors());
        $this->assertArrayHasKey('d_effective_date', $invalid->getErrors());
        $this->assertArrayHasKey('i_status', $invalid->getErrors());
    }
}
