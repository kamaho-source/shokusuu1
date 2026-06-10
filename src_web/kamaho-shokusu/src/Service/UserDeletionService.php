<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\TableRegistry;

/**
 * ユーザーソフトデリートサービス
 *
 * i_del_flag を立てて無効化し、MUserGroup の active_flag も無効化する。
 */
class UserDeletionService
{
    /**
     * @param mixed  $user      MUserInfo エンティティ
     * @param string $updatedBy 操作者ユーザー名
     * @param int    $actorId   操作者ユーザーID
     * @param string $ipAddress 操作元IPアドレス
     * @return bool
     */
    public function softDelete(mixed $user, string $updatedBy, int $actorId = 0, string $ipAddress = ''): bool
    {
        $userInfoTable  = TableRegistry::getTableLocator()->get('MUserInfo');
        $userGroupTable = TableRegistry::getTableLocator()->get('MUserGroup');

        $user->i_del_flag    = 1;
        $user->i_enable      = 1;
        $user->dt_update     = date('Y-m-d H:i:s');
        $user->c_update_user = $updatedBy;

        $userGroups = $userGroupTable->find()
            ->where(['i_id_user' => $user->i_id_user, 'active_flag' => 0])
            ->all();

        foreach ($userGroups as $group) {
            $group->active_flag   = 1;
            $group->dt_update     = date('Y-m-d H:i:s');
            $group->c_update_user = $updatedBy;
            $userGroupTable->save($group);
        }

        $result = (bool)$userInfoTable->save($user);

        AuditLogService::record(
            'user',
            'user_delete',
            $updatedBy,
            $actorId,
            'm_user_info',
            (string)$user->i_id_user,
            ['target_user_name' => $user->c_user_name],
            $ipAddress ?: null,
            $result ? 1 : 0
        );

        return $result;
    }
}
