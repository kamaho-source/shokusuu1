<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\I18n\DateTime;
use Cake\ORM\Entity;

/**
 * @property int $id
 * @property int $contact_id
 * @property string $body
 * @property string $author_type 'admin'（管理者からの返信）または 'user'（問い合わせ者本人からの返信）
 * @property DateTime $sent_at
 * @property DateTime $created
 * @property DateTime $modified
 */
class TContactReply extends Entity
{
    public const AUTHOR_ADMIN = 'admin';
    public const AUTHOR_USER  = 'user';

    protected array $_accessible = [
        'contact_id'  => true,
        'body'        => true,
        'author_type' => true,
        'sent_at'     => true,
    ];
}
