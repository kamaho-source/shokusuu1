<?php
declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * 競合・重複の例外（HTTP 409 に対応）。
 */
final class ConflictException extends DomainException
{
    public function __construct(string $message = '既に登録されています。')
    {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return 409;
    }
}
