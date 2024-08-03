<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Authentication\PasswordHasher\DefaultPasswordHasher;
use Cake\ORM\Entity;
use SlevomatCodingStandard\Sniffs\Functions\StrictCallSniff;

/**
 * MUserInfo Entity
 *
 * @property int $i_id_user
 * @property string|null $c_login_account
 * @property string|null $c_login_passwd
 * @property string|null $c__user_name
 * @property int|null $i_admin
 * @property int|null $i_disp__no
 * @property int|null $i_enable
 * @property int|null $i_del_flag
 * @property \Cake\I18n\DateTime|null $dt_create
 * @property string|null $c_create_user
 * @property \Cake\I18n\DateTime|null $dt_update
 * @property string|null $c_update_user
 */
class MUserInfo extends Entity
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
        'c_login_account' => true,
        'c_login_passwd' => true,
        'c__user_name' => true,
        'i_admin' => true,
        'i_disp__no' => true,
        'i_enable' => true,
        'i_del_flag' => true,
        'dt_create' => true,
        'c_create_user' => true,
        'dt_update' => true,
        'c_update_user' => true,
    ];

    protected function _setCLoginPasswd(string $password) : ?string
    {
        if (strlen($password) > 0) {
            return (new DefaultPasswordHasher())->hash($password);
        }
        return null;
    }

}
