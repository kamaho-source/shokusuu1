<?php
declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * リソース未存在の例外（HTTP 404 に対応）。
 */
final class NotFoundException extends DomainException
{
    public function __construct(string $message = 'リソースが見つかりません。')
    {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return 404;
    }
}
