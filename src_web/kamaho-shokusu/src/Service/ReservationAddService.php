<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\Table;

class ReservationAddService
{
    public function buildRoomList(Table $roomTable): array
    {
        return $roomTable->find('list', [
            'keyField'   => 'i_id_room',
            'valueField' => 'c_room_name',
        ])->toArray();
    }

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
