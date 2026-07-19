<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * MRoomTransferSchedule Entity
 *
 * @property int $i_id
 * @property int $i_id_user
 * @property int|null $i_id_room_from
 * @property int $i_id_room_to
 * @property \Cake\I18n\Date $d_effective_date
 * @property int $i_status
 * @property string|null $c_create_user
 * @property \Cake\I18n\DateTime|null $dt_create
 * @property string|null $c_update_user
 * @property \Cake\I18n\DateTime|null $dt_update
 * @property \App\Model\Entity\MUserInfo $m_user_info
 * @property \App\Model\Entity\MRoomInfo $room_from
 * @property \App\Model\Entity\MRoomInfo $room_to
 * @property int|null $tenant_id
 * @property int|null $facility_id
 */
class MRoomTransferSchedule extends Entity
{
    protected array $_accessible = [
        'i_id_user'        => true,
        'i_id_room_from'   => true,
        'i_id_room_to'     => true,
        'd_effective_date' => true,
        'i_status'         => true,
        'c_create_user'    => true,
        'dt_create'        => true,
        'c_update_user'    => true,
        'dt_update'        => true,
        'tenant_id'        => false,
        'facility_id'      => false,
    ];
}
