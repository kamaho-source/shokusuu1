<?php
declare(strict_types=1);

namespace App\Service;

use Cake\I18n\Date;

class ReservationDatePolicy
{
    private const CHANGE_WINDOW_DAYS = 14;
    private const NORMAL_ORDER_MIN_DAYS = 15;

    public function validateReservationDate(?string $reservationDate): string|bool
    {
        if (empty($reservationDate)) {
            return '予約日が指定されていません。';
        }

        try {
            $reservationDateObj = new Date($reservationDate);
        } catch (\Exception $e) {
            return '無効な日付フォーマットです。';
        }

        $minDate = $this->minimumOrderDate();

        if ($reservationDateObj < $minDate) {
            return sprintf(
                '通常発注は「きょうから15日目以降」のみ登録できます（%s 以降）。',
                $minDate->i18nFormat('yyyy-MM-dd')
            );
        }

        return true;
    }

    public function changeBoundaryDate(?Date $today = null, ?string $timezone = null): Date
    {
        $today = $today ?? $this->today($timezone);

        return $today->addDays(self::CHANGE_WINDOW_DAYS);
    }

    public function minimumOrderDate(?Date $today = null, ?string $timezone = null): Date
    {
        $today = $today ?? $this->today($timezone);

        return $today->addDays(self::NORMAL_ORDER_MIN_DAYS);
    }

    public function shouldUseChangeFlag(Date $targetDate, ?Date $today = null, ?string $timezone = null): bool
    {
        return $targetDate <= $this->changeBoundaryDate($today, $timezone);
    }

    public function isLastMinuteWindow(Date $targetDate, ?Date $today = null, ?string $timezone = null): bool
    {
        $today = $today ?? $this->today($timezone);
        $boundary = $this->changeBoundaryDate($today, $timezone);

        return $targetDate >= $today && $targetDate <= $boundary;
    }

    public function judgeColumn(Date $targetDate, ?Date $today = null, ?string $timezone = null): string
    {
        return $this->shouldUseChangeFlag($targetDate, $today, $timezone)
            ? 'i_change_flag'
            : 'eat_flag';
    }

    private function today(?string $timezone = null): Date
    {
        return $timezone !== null ? Date::today($timezone) : Date::today();
    }
}
