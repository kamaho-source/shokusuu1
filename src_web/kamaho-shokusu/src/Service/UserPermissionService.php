<?php
declare(strict_types=1);

namespace App\Service;

use App\Domain\ValueObject\UserRole;
use Cake\ORM\TableRegistry;

/**
 * ユーザー権限更新サービス
 *
 * i_admin フィールドの更新を担う（管理者フラグ・ブロック長フラグ共通）。
 */
class UserPermissionService
{
    /**
     * @param \App\Model\Entity\MUserInfo $user             MUserInfo エンティティ（取得・認可済み）
     * @param int    $value             新しい権限値
     * @param string $updatedBy         操作者ユーザー名
     * @param int    $actorId           操作者ユーザーID
     * @param string $ipAddress         操作元IPアドレス
     * @param string $actorLoginId      操作者ログインID
     * @param bool   $allowSystemAdmin  SYSTEM_ADMIN(3) への遷移を許可するか（システム管理者専用画面からの呼び出しのみ true）
     * @return bool
     */
    public function updatePermission(
        \App\Model\Entity\MUserInfo $user,
        int $value,
        string $updatedBy,
        int $actorId = 0,
        string $ipAddress = '',
        string $actorLoginId = '',
        bool $allowSystemAdmin = false
    ): bool {
        $oldValue = $user->i_admin;

        $allowedValues = $allowSystemAdmin
            ? [UserRole::GENERAL, UserRole::ADMIN, UserRole::BLOCK_LEADER, UserRole::SYSTEM_ADMIN]
            : [UserRole::GENERAL, UserRole::ADMIN, UserRole::BLOCK_LEADER];

        if (!in_array($value, $allowedValues, true)) {
            AuditLogService::record(
                'user',
                'user_permission_change',
                $updatedBy,
                $actorId,
                'm_user_info',
                (string)$user->i_id_user,
                [
                    'target_user_name' => $user->c_user_name,
                    'old_i_admin'      => $oldValue,
                    'new_i_admin'      => $value,
                ],
                $ipAddress ?: null,
                0,
                $actorLoginId
            );

            return false;
        }

        $table = TableRegistry::getTableLocator()->get('MUserInfo');

        $user->i_admin       = $value;
        $user->dt_update     = date('Y-m-d H:i:s');
        $user->c_update_user = $updatedBy;

        $result = (bool)$table->save($user);

        AuditLogService::record(
            'user',
            'user_permission_change',
            $updatedBy,
            $actorId,
            'm_user_info',
            (string)$user->i_id_user,
            [
                'target_user_name' => $user->c_user_name,
                'old_i_admin'      => $oldValue,
                'new_i_admin'      => $value,
            ],
            $ipAddress ?: null,
            $result ? 1 : 0,
            $actorLoginId
        );

        return $result;
    }
}
