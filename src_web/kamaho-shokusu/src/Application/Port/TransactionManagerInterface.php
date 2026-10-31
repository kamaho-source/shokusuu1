<?php
declare(strict_types=1);

namespace App\Application\Port;

/**
 * 予約の書き込みのトランザクション（実装は Infrastructure）。
 *
 * 途中で結果を返して取り消す処理（権限の拒否・競合など）があるため、開始・確定・取消を個別に呼べる形にしている。
 */
interface TransactionManagerInterface
{
    public function begin(): void;

    public function commit(): void;

    public function rollback(): void;

    public function inTransaction(): bool;
}
