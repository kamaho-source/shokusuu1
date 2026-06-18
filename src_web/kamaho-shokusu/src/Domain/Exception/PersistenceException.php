<?php
declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * DB保存失敗の例外（HTTP 500 に対応）。
 */
final class PersistenceException extends DomainException
{
    public function __construct(string $message = 'データの保存に失敗しました。')
    {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return 500;
    }
}
