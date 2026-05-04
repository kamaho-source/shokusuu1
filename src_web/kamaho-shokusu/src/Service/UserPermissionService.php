<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\TableRegistry;

/**
 * ユーザー権限更新サービス
 *
 * i_admin フィールドの更新を担う（管理者フラグ・ブロック長フラグ共通）。
 */
class UserPermissionService
{
    /**
     * @param mixed  $user      MUserInfo エンティティ（取得・認可済み）
     * @param int    $value     新しい権限値
     * @param string $updatedBy 操作者ユーザー名
     * @return bool
     */
    public function updatePermission(mixed $user, int $value, string $updatedBy): bool
    {
        $table = TableRegistry::getTableLocator()->get('MUserInfo');

        $user->i_admin       = $value;
        $user->dt_update     = date('Y-m-d H:i:s');
        $user->c_update_user = $updatedBy;

        return (bool)$table->save($user);
    }
}
