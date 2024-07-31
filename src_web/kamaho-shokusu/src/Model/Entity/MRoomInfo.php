<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * MRoomInfo Entity
 *
 * @property int $i_id_room
 * @property string|null $c_room_name
 * @property int|null $i_disp_no
 * @property int|null $i_enable
 * @property int|null $i_del_flg
 * @property \Cake\I18n\DateTime|null $dt_create
 * @property string|null $c_create_user
 * @property \Cake\I18n\DateTime|null $dt_update
 * @property string|null $c_update_user
 */
class MRoomInfo extends Entity
{
    /**
     * Fields that can be mass assigned using newEntity() or patchEntity().
     *
     * Note that when '*' is set to true, this allows all unspecified fields to
     * be mass assigned. For security purposes, it is advised to set '*' to false
     * (or remove it), and explicitly make individual fields accessible as needed.
     *
     * @var array<string, bool>
     */
    protected array $_accessible = [
        'c_room_name' => true,
        'i_disp_no' => true,
        'i_enable' => true,
        'i_del_flg' => true,
        'dt_create' => true,
        'c_create_user' => true,
        'dt_update' => true,
        'c_update_user' => true,
    ];
}
