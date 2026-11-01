<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\Fake;

use App\Application\Port\TransactionManagerInterface;

/**
 * TransactionManagerInterface の Spy（呼ばれた順番を覚えておく）。
 */
final class SpyTransaction implements TransactionManagerInterface
{
    /** @var list<string> */
    public array $calls = [];

    private bool $open = false;

    public function begin(): void
    {
        $this->calls[] = 'begin';
        $this->open    = true;
    }

    public function commit(): void
    {
        $this->calls[] = 'commit';
        $this->open    = false;
    }

    public function rollback(): void
    {
        $this->calls[] = 'rollback';
        $this->open    = false;
    }

    public function inTransaction(): bool
    {
        return $this->open;
    }
}
