<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\TReservationInfoTable;
use Cake\TestSuite\TestCase;

/**
 * App\Model\Table\TReservationInfoTable Test Case
 */
class TReservationInfoTableTest extends TestCase
{
    /**
     * Test subject
     *
     * @var \App\Model\Table\TReservationInfoTable
     */
    protected $TReservationInfo;

    /**
     * Fixtures
     *
     * @var list<string>
     */
    protected array $fixtures = [
        'app.TReservationInfo',
    ];

    /**
     * setUp method
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $config = $this->getTableLocator()->exists('TReservationInfo') ? [] : ['className' => TReservationInfoTable::class];
        $this->TReservationInfo = $this->getTableLocator()->get('TReservationInfo', $config);
    }

    /**
     * tearDown method
     *
     * @return void
     */
    protected function tearDown(): void
    {
        unset($this->TReservationInfo);

        parent::tearDown();
    }

    /**
     * Test validationDefault method
     *
     * @return void
     * @uses \App\Model\Table\TReservationInfoTable::validationDefault()
     */
    public function testValidationDefault(): void
    {
        $this->markTestIncomplete('Not implemented yet.');
    }
}
