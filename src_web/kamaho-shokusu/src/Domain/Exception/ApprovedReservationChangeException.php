<?php
declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * 承認済みの予約を変えようとした（HTTP 409 に対応）。承認済みの行は変えない。
 */
final class ApprovedReservationChangeException extends DomainException
{
    public function __construct(string $message = '承認済みの予約は変更できません。')
    {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return 409;
    }
}
