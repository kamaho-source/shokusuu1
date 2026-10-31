<?php
declare(strict_types=1);

namespace App\Test\TestCase\Infrastructure\Persistence\Table;

use App\Infrastructure\Persistence\Table\TContactRepliesTable;
use Cake\TestSuite\TestCase;

/**
 * App\Infrastructure\Persistence\Table\TContactRepliesTable Test Case
 */
class TContactRepliesTableTest extends TestCase
{
    protected TContactRepliesTable $TContactReplies;

    protected function setUp(): void
    {
        parent::setUp();
        $config = $this->getTableLocator()->exists('TContactReplies')
            ? []
            : ['className' => TContactRepliesTable::class];
        $this->TContactReplies = $this->getTableLocator()->get('TContactReplies', $config);
    }

    protected function tearDown(): void
    {
        unset($this->TContactReplies);

        parent::tearDown();
    }

    /**
     * @uses \App\Infrastructure\Persistence\Table\TContactRepliesTable::validationDefault()
     */
    public function testValidationDefault(): void
    {
        $valid = $this->TContactReplies->newEntity([
            'contact_id' => 1,
            'body' => '確認して対応いたします。',
        ]);
        $this->assertEmpty($valid->getErrors());

        $invalid = $this->TContactReplies->newEntity([
            'contact_id' => 1,
            'body' => '',
        ]);
        $this->assertArrayHasKey('body', $invalid->getErrors());
    }
}
