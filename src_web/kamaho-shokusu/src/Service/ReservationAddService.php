<?php
declare(strict_types=1);

namespace App\Service;

class ReservationAddService
{
    public function ensureReservationDate(array $data, string $date): array
    {
        if (empty($data['d_reservation_date'])) {
            $data['d_reservation_date'] = $date;
        }
        return $data;
    }

    public function validateLunchBento(array $data): ?string
    {
        $lunchOn = !empty($data['lunch']);
        $bentoOn = !empty($data['bento']);
        if ($lunchOn && $bentoOn) {
            return '昼食と弁当は同時に予約できません。どちらか一方を選択してください。';
        }
        return null;
    }

    public function validateDate(string $date, ReservationDatePolicy $policy): string|bool
    {
        return $policy->validateReservationDate($date);
    }
}
