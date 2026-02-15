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
        $valid = $this->MUserGroup->newEntity([
            'i_id_user' => 2,
            'i_id_room' => 1,
            'active_flag' => 0,
            'dt_create' => '2024-07-01 10:00:00',
            'c_create_user' => 'テストユーザー',
        ]);
        $this->assertEmpty($valid->getErrors());

        $invalid = $this->MUserGroup->newEntity([
            'i_id_room' => 1,
            'active_flag' => 0,
        ]);
        $this->assertArrayHasKey('i_id_user', $invalid->getErrors());
    }
}
