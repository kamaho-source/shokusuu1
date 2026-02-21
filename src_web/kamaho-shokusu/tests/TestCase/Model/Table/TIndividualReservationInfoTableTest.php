<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\TIndividualReservationInfoTable;
use Cake\TestSuite\TestCase;

/**
 * App\Model\Table\TIndividualReservationInfoTable Test Case
 */
class TIndividualReservationInfoTableTest extends TestCase
{
    /**
     * Test subject
     *
     * @var \App\Model\Table\TIndividualReservationInfoTable
     */
    protected $TIndividualReservationInfo;

    /**
     * Fixtures
     *
     * @var list<string>
     */
    protected array $fixtures = [
        'app.TIndividualReservationInfo',
    ];

    /**
     * setUp method
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $config = $this->getTableLocator()->exists('TIndividualReservationInfo') ? [] : ['className' => TIndividualReservationInfoTable::class];
        $this->TIndividualReservationInfo = $this->getTableLocator()->get('TIndividualReservationInfo', $config);
    }

    /**
     * tearDown method
     *
     * @return void
     */
    protected function tearDown(): void
    {
        unset($this->TIndividualReservationInfo);

        parent::tearDown();
    }

    /**
     * Test validationDefault method
     *
     * @return void
     * @uses \App\Model\Table\TIndividualReservationInfoTable::validationDefault()
     */
    public function testValidationDefault(): void
    {
        $valid = $this->TIndividualReservationInfo->newEntity([
            'i_id_user' => 1,
            'd_reservation_date' => '2024-09-07',
            'i_reservation_type' => 1,
            'i_id_room' => 1,
            'eat_flag' => 1,
        ]);
        $this->assertEmpty($valid->getErrors());

        $invalid = $this->TIndividualReservationInfo->newEntity([
            'd_reservation_date' => '2024-09-07',
            'i_reservation_type' => 1,
            'i_id_room' => 1,
        ]);
        $this->assertArrayHasKey('i_id_user', $invalid->getErrors());
    }
}
