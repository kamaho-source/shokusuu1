<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\MRoomInfoTable;
use Cake\TestSuite\TestCase;

/**
 * App\Model\Table\MRoomInfoTable Test Case
 */
class MRoomInfoTableTest extends TestCase
{
    /**
     * Test subject
     *
     * @var \App\Model\Table\MRoomInfoTable
     */
    protected $MRoomInfo;

    /**
     * Fixtures
     *
     * @var list<string>
     */
    protected array $fixtures = [
        'app.MRoomInfo',
    ];

    /**
     * setUp method
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $config = $this->getTableLocator()->exists('MRoomInfo') ? [] : ['className' => MRoomInfoTable::class];
        $this->MRoomInfo = $this->getTableLocator()->get('MRoomInfo', $config);
    }

    /**
     * tearDown method
     *
     * @return void
     */
    protected function tearDown(): void
    {
        unset($this->MRoomInfo);

        parent::tearDown();
    }

    /**
     * Test validationDefault method
     *
     * @return void
     * @uses \App\Model\Table\MRoomInfoTable::validationDefault()
     */
    public function testValidationDefault(): void
    {
        $this->markTestIncomplete('Not implemented yet.');
    }
}
