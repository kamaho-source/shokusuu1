<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * MNotice Entity
 *
 * @property int $i_id
 * @property string $c_title
 * @property string|null $c_body
 * @property \Cake\I18n\Date|null $d_start
 * @property \Cake\I18n\Date|null $d_end
 * @property int $i_importance
 * @property int|null $i_id_user_created
 * @property string|null $c_create_user
 * @property \Cake\I18n\DateTime|null $dt_create
 * @property string|null $c_update_user
 * @property \Cake\I18n\DateTime|null $dt_update
 */
class MNotice extends Entity
{
    protected array $_accessible = [
        'c_title'           => true,
        'c_body'            => true,
        'd_start'           => true,
        'd_end'             => true,
        'i_importance'      => true,
        'i_id_user_created' => true,
        'c_create_user'     => true,
        'dt_create'         => true,
        'c_update_user'     => true,
        'dt_update'         => true,
    ];
}
