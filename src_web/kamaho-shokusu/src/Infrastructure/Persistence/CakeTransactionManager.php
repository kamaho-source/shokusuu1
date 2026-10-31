<?php
declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Application\Port\TransactionManagerInterface;
use Cake\Database\Connection;

/**
 * TransactionManagerInterface の CakePHP 実装（予約テーブルの接続を使う）。
 */
final class CakeTransactionManager implements TransactionManagerInterface
{
    public function __construct(private readonly Connection $connection)
    {
    }

    public function begin(): void
    {
        $this->connection->begin();
    }

    public function commit(): void
    {
        $this->connection->commit();
    }

    public function rollback(): void
    {
        $this->connection->rollback();
    }

    public function inTransaction(): bool
    {
        return $this->connection->inTransaction();
    }
}
