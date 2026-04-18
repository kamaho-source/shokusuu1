<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * @property int $id
 * @property string $category
 * @property string $name
 * @property string $email
 * @property string $body
 * @property int|null $user_id
 * @property \Cake\I18n\DateTime $created
 * @property \Cake\I18n\DateTime $modified
 */
class TContact extends Entity
{
    protected array $_accessible = [
        'category' => true,
        'name'     => true,
        'email'    => true,
        'body'     => true,
        'user_id'  => true,
    ];
}
