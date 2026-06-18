<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\TableRegistry;

/**
 * ユーザー復元サービス
 *
 * ソフトデリートされたユーザーを復元し、MUserGroup の active_flag も再有効化する。
 * 処理はトランザクション内で実行される。
 *
 * @throws \Exception 保存失敗時
 */
class UserRestoreService
{
    /**
     * @param \App\Model\Entity\MUserInfo $user      MUserInfo エンティティ（i_del_flag === 1 であること）
     * @param string $updatedBy 操作者ユーザー名
     * @param int    $actorId   操作者ユーザーID
     * @param string $ipAddress 操作元IPアドレス
     * @return bool
     * @throws \Exception
     */
    public function restore(\App\Model\Entity\MUserInfo $user, string $updatedBy, int $actorId = 0, string $ipAddress = ''): bool
    {
        $userInfoTable  = TableRegistry::getTableLocator()->get('MUserInfo');
        $userGroupTable = TableRegistry::getTableLocator()->get('MUserGroup');

        $conn = $userInfoTable->getConnection();
        $conn->begin();

        try {
            $user->i_del_flag    = 0;
            $user->dt_update     = date('Y-m-d H:i:s');
            $user->c_update_user = $updatedBy;

            if (!$userInfoTable->save($user)) {
                throw new \Exception('ユーザー情報の更新に失敗しました。');
            }

            $userGroups = $userGroupTable->find()
                ->where(['i_id_user' => $user->i_id_user])
                ->all();

            foreach ($userGroups as $group) {
                $group->active_flag   = 0;
                $group->dt_update     = date('Y-m-d H:i:s');
                $group->c_update_user = $updatedBy;

                if (!$userGroupTable->save($group)) {
                    throw new \Exception('グループ情報の更新に失敗しました。');
                }
            }

            $conn->commit();

            AuditLogService::record(
                'user',
                'user_restore',
                $updatedBy,
                $actorId,
                'm_user_info',
                (string)$user->i_id_user,
                ['target_user_name' => $user->c_user_name],
                $ipAddress ?: null,
                1
            );

            return true;
        } catch (\Exception $e) {
            $conn->rollback();

            AuditLogService::record(
                'user',
                'user_restore',
                $updatedBy,
                $actorId,
                'm_user_info',
                (string)$user->i_id_user,
                ['target_user_name' => $user->c_user_name, 'error' => $e->getMessage()],
                $ipAddress ?: null,
                0
            );

            throw $e;
        }
    }
}
