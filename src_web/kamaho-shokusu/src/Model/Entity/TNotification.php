<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * @property int $i_id_notification
 * @property int $i_id_user
 * @property string $c_notification_type
 * @property string $c_title
 * @property string $c_message
 * @property string|null $c_link
 * @property int $i_is_read
 * @property \Cake\I18n\DateTime|null $dt_read
 * @property \Cake\I18n\DateTime $dt_create
 */
class TNotification extends Entity
{
    protected array $_accessible = [
        'i_id_user' => true,
        'c_notification_type' => true,
        'c_title' => true,
        'c_message' => true,
        'c_link' => true,
        'i_is_read' => true,
        'dt_read' => true,
        'dt_create' => true,
    ];
}
