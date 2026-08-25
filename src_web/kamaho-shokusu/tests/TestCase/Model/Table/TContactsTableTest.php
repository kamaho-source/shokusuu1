<?php
declare(strict_types=1);

namespace App\Test\TestCase\Model\Table;

use App\Model\Table\TContactsTable;
use Cake\TestSuite\TestCase;

/**
 * App\Model\Table\TContactsTable Test Case
 */
class TContactsTableTest extends TestCase
{
    protected TContactsTable $TContacts;

    protected function setUp(): void
    {
        parent::setUp();
        $config = $this->getTableLocator()->exists('TContacts') ? [] : ['className' => TContactsTable::class];
        $this->TContacts = $this->getTableLocator()->get('TContacts', $config);
    }

    protected function tearDown(): void
    {
        unset($this->TContacts);

        parent::tearDown();
    }

    /**
     * @uses \App\Model\Table\TContactsTable::validationDefault()
     */
    public function testValidationDefault(): void
    {
        $valid = $this->TContacts->newEntity([
            'category' => '不具合報告',
            'name' => 'テスト太郎',
            'email' => 'test@example.com',
            'body' => 'ログイン画面でエラーが発生します。',
        ]);
        $this->assertEmpty($valid->getErrors());

        $invalid = $this->TContacts->newEntity([
            'category' => '該当なし',
            'email' => 'not-an-email',
            'body' => '短文',
        ]);
        $this->assertArrayHasKey('category', $invalid->getErrors());
        $this->assertArrayHasKey('name', $invalid->getErrors());
        $this->assertArrayHasKey('email', $invalid->getErrors());
        $this->assertArrayHasKey('body', $invalid->getErrors());
    }
}
