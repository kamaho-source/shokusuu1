<?php
declare(strict_types=1);

namespace App\Model\Entity;

use Cake\ORM\Entity;

/**
 * TAuditLog Entity
 *
 * @property int $i_id_audit
 * @property string $c_category        カテゴリ (user/reservation/actual_meal/approval/master/system)
 * @property string $c_action          操作種別
 * @property string|null $c_target_table
 * @property string|null $c_target_id
 * @property int|null $i_actor_user_id
 * @property string $c_actor_user_name
 * @property string|null $c_ip_address
 * @property int $i_result             1:成功 0:失敗
 * @property string|null $c_detail     JSON文字列
 * @property \Cake\I18n\DateTime $dt_create
 */
class TAuditLog extends Entity
{
    protected array $_accessible = [
        'c_category'        => true,
        'c_action'          => true,
        'c_target_table'    => true,
        'c_target_id'       => true,
        'i_actor_user_id'   => true,
        'c_actor_user_name' => true,
        'c_ip_address'      => true,
        'i_result'          => true,
        'c_detail'          => true,
        'dt_create'         => true,
    ];
}
