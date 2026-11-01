<?php
declare(strict_types=1);

namespace App\Application\Port;

/**
 * 「今日」を基準にした予約日の検証（ルール本体は Domain の ReservationDateRule。実装が今日を補う）。
 */
interface ReservationDatePolicyInterface
{
    /**
     * 通常予約として登録できる日か。
     *
     * @return string|bool true = 登録できる / string = できない理由
     */
    public function validateReservationDate(?string $reservationDate): string|bool;

    /** 今日より前の日か（日付として読めなければ false）。 */
    public function isPastDate(string $date): bool;
}
