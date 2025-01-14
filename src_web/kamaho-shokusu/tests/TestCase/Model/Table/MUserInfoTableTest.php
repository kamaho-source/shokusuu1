<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\MUserInfoTable;
use Cake\TestSuite\TestCase;

/**
 * App\Model\Table\MUserInfoTable Test Case
 */
class MUserInfoTableTest extends TestCase
{
    /**
     * Test subject
     *
     * @var \App\Model\Table\MUserInfoTable
     */
    protected $MUserInfo;

    /**
     * Fixtures
     *
     * @var list<string>
     */
    protected array $fixtures = [
        'app.MUserInfo',
    ];

    /**
     * setUp method
     *
     * @return void
     */
    protected function setUp(): void
    {
        parent::setUp();
        $config = $this->getTableLocator()->exists('MUserInfo') ? [] : ['className' => MUserInfoTable::class];
        $this->MUserInfo = $this->getTableLocator()->get('MUserInfo', $config);
    }

    /**
     * tearDown method
     *
     * @return void
     */
    protected function tearDown(): void
    {
        unset($this->MUserInfo);

        parent::tearDown();
    }

    /**
     * Test validationDefault method
     *
     * @return void
     * @uses \App\Model\Table\MUserInfoTable::validationDefault()
     */
    public function testValidationDefault(): void
    {
        $this->markTestIncomplete('Not implemented yet.');
    }
}
