<?php
declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * 認可失敗の例外（HTTP 403 に対応）。
 */
final class UnauthorizedException extends DomainException
{
    public function __construct(string $message = '操作権限がありません。')
    {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return 403;
    }
}
