<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\MNoticeTable;
use Cake\TestSuite\TestCase;

/**
 * App\Model\Table\MNoticeTable Test Case
 */
class MNoticeTableTest extends TestCase
{
    protected array $fixtures = [
        'app.MNotice',
    ];

    protected MNoticeTable $MNotice;

    protected function setUp(): void
    {
        parent::setUp();
        $config = $this->getTableLocator()->exists('MNotice') ? [] : ['className' => MNoticeTable::class];
        $this->MNotice = $this->getTableLocator()->get('MNotice', $config);
    }

    protected function tearDown(): void
    {
        unset($this->MNotice);

        parent::tearDown();
    }

    /**
     * @uses \App\Model\Table\MNoticeTable::validationDefault()
     */
    public function testValidationDefault(): void
    {
        $valid = $this->MNotice->newEntity([
            'c_title' => 'メンテナンスのお知らせ',
            'i_importance' => MNoticeTable::IMPORTANCE_HIGH,
            'i_type' => MNoticeTable::TYPE_NORMAL,
        ]);
        $this->assertEmpty($valid->getErrors());

        $invalid = $this->MNotice->newEntity([
            'i_importance' => 99,
        ]);
        $this->assertArrayHasKey('c_title', $invalid->getErrors());
        $this->assertArrayHasKey('i_importance', $invalid->getErrors());
    }
}
