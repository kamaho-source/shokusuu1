<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\Fake;

use App\Application\Port\ReservationDatePolicyInterface;
use App\Domain\Service\ReservationDateRule;
use Cake\Chronos\ChronosDate;

/**
 * テスト用の予約日の検証。今日は Chronos::setTestNow で固定した日（ルールは本物の ReservationDateRule）。
 */
final class FakeReservationDatePolicy implements ReservationDatePolicyInterface
{
    public function validateReservationDate(?string $reservationDate): string|bool
    {
        return ReservationDateRule::normalOrderError(new ChronosDate((string)$reservationDate), ChronosDate::today('Asia/Tokyo')) ?? true;
    }

    public function isPastDate(string $date): bool
    {
        return ReservationDateRule::isPast(new ChronosDate($date), ChronosDate::today('Asia/Tokyo'));
    }
}
