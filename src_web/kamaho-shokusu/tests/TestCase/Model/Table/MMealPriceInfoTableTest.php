<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\MMealPriceInfoTable;
use Cake\TestSuite\TestCase;

/**
 * App\Model\Table\MMealPriceInfoTable Test Case
 */
class MMealPriceInfoTableTest extends TestCase
{
    protected array $fixtures = [
        'app.MMealPriceInfo',
    ];

    protected MMealPriceInfoTable $MMealPriceInfo;

    protected function setUp(): void
    {
        parent::setUp();
        $config = $this->getTableLocator()->exists('MMealPriceInfo') ? [] : ['className' => MMealPriceInfoTable::class];
        $this->MMealPriceInfo = $this->getTableLocator()->get('MMealPriceInfo', $config);
    }

    protected function tearDown(): void
    {
        unset($this->MMealPriceInfo);

        parent::tearDown();
    }

    /**
     * @uses \App\Model\Table\MMealPriceInfoTable::validationDefault()
     */
    public function testValidationDefault(): void
    {
        $valid = $this->MMealPriceInfo->newEntity([
            'i_fiscal_year' => 2026,
            'i_morning_price' => 500,
            'i_lunch_price' => 700,
            'i_dinner_price' => 700,
            'i_bento_price' => 600,
        ]);
        $this->assertEmpty($valid->getErrors());

        $invalid = $this->MMealPriceInfo->newEntity([
            'i_fiscal_year' => 'not-a-year',
        ]);
        $this->assertArrayHasKey('i_fiscal_year', $invalid->getErrors());
    }
}
