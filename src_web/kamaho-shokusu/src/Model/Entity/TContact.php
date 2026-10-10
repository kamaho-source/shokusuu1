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
 * @property string|null $reply_token 受信メール返信をスレッドへ紐付ける固有トークン（システム生成のみ・マスアサイン不可）
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
        // reply_token は意図的に含めない。フォーム入力からの上書きを防ぐため、
        // Service 層でのみ直接プロパティ代入で設定する。
    ];
}
