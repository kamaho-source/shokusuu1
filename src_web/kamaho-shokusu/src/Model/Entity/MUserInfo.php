<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Authentication\PasswordHasher\DefaultPasswordHasher;
use Cake\ORM\Entity;
use Cake\I18n\DateTime;

/**
 * MUserInfo Entity
 *
 * @property int $i_id_user
 * @property string|null $c_login_account
 * @property string|null $c_login_passwd
 * @property string|null $c_user_name
 * @property int|null $i_admin
 * @property int|null $i_disp_no
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
        'i_id_staff' => true,
        'i_id_user' => true,
        'c_login_account' => true,
        'c_login_passwd' => true,
        'c_user_name' => true,
        'i_user_gender' => true,
        'i_user_age'=> true,
        'i_user_level'=> true,
        'i_user_rank'=> true,
        'i_admin' => true,
        'i_disp_no' => true,
        'i_enable' => true,
        'i_del_flag' => true,
        'dt_create' => true,
        'c_create_user' => true,
        'dt_update' => true,
        'c_update_user' => true,
    ];

    /**
     * @param string $password
     * @return string|null
     */
    protected function _setCLoginPasswd(?string $password): ?string
    {
        if ($password && password_get_info($password)['algo'] === 0) {
            // すでにハッシュ化されていない場合のみハッシュ化する
            return (new DefaultPasswordHasher())->hash($password);
        }
        return $password;
    }

    /**
     * Get user rooms by user ID.
     *
     * @param int $userId
     * @return array
     */
    public function getUserRooms(int $userId): array
    {
        // プロパティ`getConnection`がエンティティに定義されていないので、使用できません。
        // 代わりに、テーブルクラスからクエリを実行してください。
        $query = $this->getTableLocator()->get('MUserGroup')->find()
            ->select(['i_id_room'])
            ->where(['i_id_user' => $userId]);

        return $query->all()->toArray();
    }
}
