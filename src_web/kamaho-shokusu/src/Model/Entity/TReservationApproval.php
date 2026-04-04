<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

class TReservationApproval extends Entity
{
    protected array $_accessible = [
        'i_id_user' => true,
        'd_reservation_date' => true,
        'i_id_room' => true,
        'i_reservation_type' => true,
        'i_requested_flag' => true,
        'i_status' => true,
        'c_reason' => true,
        'i_reviewer_user' => true,
        'dt_reviewed' => true,
        'dt_create' => true,
        'c_create_user' => true,
        'dt_update' => true,
        'c_update_user' => true,
    ];
}
