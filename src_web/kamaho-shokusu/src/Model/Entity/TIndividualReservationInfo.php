<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * TIndividualReservationInfo Entity
 *
 * @property int $i_id_user
 * @property \Cake\I18n\Date $d_reservation_date
 * @property int|null $i_reservation_type
 * @property int $i_id_room
 * @property int|null $eat_flag
 * @property \Cake\I18n\DateTime|null $dt_create
 * @property string|null $c_create_user
 * @property \Cake\I18n\DateTime|null $dt_update
 * @property string|null $c_update_user
 */
class TIndividualReservationInfo extends Entity
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
        'i_reservation_type' => true,
        'eat_flag' => true,
        'dt_create' => true,
        'c_create_user' => true,
        'dt_update' => true,
        'c_update_user' => true,
    ];



    public function initialize(array $config): void
    {
        parent::initialize($config);
        $this->setTable('t_individual_reservation_info');
        $this->setDisplayField('i_id_user');

    }


    public function getUserReservations($userId,$date)
    {
        return $this->find()
            ->where(['i_id_user' => $userId, 'd_reservation_date' => $date])
            ->all();
    }
}
