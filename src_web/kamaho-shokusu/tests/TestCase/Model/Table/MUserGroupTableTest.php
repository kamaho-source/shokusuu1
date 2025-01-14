<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\MUserGroupTable;
use Cake\TestSuite\TestCase;

/**
 * App\Model\Table\MUserGroupTable Test Case
 */
class MUserGroupTableTest extends TestCase
{
    /**
     * Test subject
     *
     * @var \App\Model\Table\MUserGroupTable
     */
    protected $MUserGroup;

    /**
     * Fixtures
     *
     * @var list<string>
     */
    protected array $fixtures = [
        'app.MUserGroup',
    ];

    /**
     * setUp method
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $config = $this->getTableLocator()->exists('MUserGroup') ? [] : ['className' => MUserGroupTable::class];
        $this->MUserGroup = $this->getTableLocator()->get('MUserGroup', $config);
    }

    /**
     * tearDown method
     *
     * @return void
     */
    protected function tearDown(): void
    {
        unset($this->MUserGroup);

        parent::tearDown();
    }

    /**
     * Test validationDefault method
     *
     * @return void
     * @uses \App\Model\Table\MUserGroupTable::validationDefault()
     */
    public function testValidationDefault(): void
    {
        $this->markTestIncomplete('Not implemented yet.');
    }
}
