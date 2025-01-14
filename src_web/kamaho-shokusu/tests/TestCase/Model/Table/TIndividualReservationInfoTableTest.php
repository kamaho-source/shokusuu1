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
        $this->markTestIncomplete('Not implemented yet.');
    }
}
