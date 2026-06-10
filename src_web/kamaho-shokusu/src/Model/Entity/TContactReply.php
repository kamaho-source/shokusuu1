<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\I18n\DateTime;
use Cake\ORM\Entity;

/**
 * @property int $id
 * @property int $contact_id
 * @property string $body
 * @property DateTime $sent_at
 * @property DateTime $created
 * @property DateTime $modified
 */
class TContactReply extends Entity
{
    protected array $_accessible = [
        'contact_id' => true,
        'body'       => true,
        'sent_at'    => true,
    ];
}
