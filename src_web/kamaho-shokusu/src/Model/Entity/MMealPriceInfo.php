<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * MMealPriceInfo Entity
 *
 * @property int $i_id
 * @property int|null $i_fiscal_year
 * @property int|null $i_morning_price
 * @property int|null $i_lunch_price
 * @property int|null $i_dinner_price
 * @property int|null $i_bento_price
 * @property \Cake\I18n\DateTime|null $dt_create
 * @property string|null $c_create_user
 * @property \Cake\I18n\DateTime|null $dt_update
 * @property string|null $c_update_user
 */
class MMealPriceInfo extends Entity
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
        'i_fiscal_year' => true,
        'i_morning_price' => true,
        'i_lunch_price' => true,
        'i_dinner_price' => true,
        'i_bento_price' => true,
        'dt_create' => true,
        'c_create_user' => true,
        'dt_update' => true,
        'c_update_user' => true,
    ];
}
