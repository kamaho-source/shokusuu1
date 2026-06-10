<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * TApprovalLog Entity
 *
 * @property int $i_id_approval
 * @property int $i_id_user
 * @property \Cake\I18n\Date $d_reservation_date
 * @property int $i_id_room
 * @property int $i_reservation_type
 * @property int $i_approval_status  1:ブロック長承認 2:管理者承認(最終) 3:差し戻し
 * @property int $i_approver_id
 * @property string|null $c_reject_reason
 * @property \Cake\I18n\DateTime $dt_create
 */
class TApprovalLog extends Entity
{
    protected array $_accessible = [
        'i_id_user'          => true,
        'd_reservation_date' => true,
        'i_id_room'          => true,
        'i_reservation_type' => true,
        'i_approval_status'  => true,
        'i_approver_id'      => true,
        'c_reject_reason'    => true,
        'dt_create'          => true,
    ];
}
