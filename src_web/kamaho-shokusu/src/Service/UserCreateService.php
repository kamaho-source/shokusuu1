<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\TableRegistry;

/**
 * ユーザー新規作成サービス
 *
 * 表示番号採番・ログインID重複チェック・ユーザー+グループ保存を担う。
 */
class UserCreateService
{
    /**
     * 次の表示番号（MAX + 1）を返す。
     */
    public function nextDisplayNo(): int
    {
        $table       = TableRegistry::getTableLocator()->get('MUserInfo');
        $maxDispNoRow = $table->find()
            ->select(['max_no' => $table->find()->func()->max('i_disp_no')])
            ->first();

        return $maxDispNoRow && $maxDispNoRow->max_no !== null
            ? ((int)$maxDispNoRow->max_no + 1)
            : 1;
    }

    /**
     * ログインIDが既に存在するか確認する。
     */
    public function loginAccountExists(string $loginId): bool
    {
        $table = TableRegistry::getTableLocator()->get('MUserInfo');
        return (bool)$table->find()->where(['c_login_account' => $loginId])->first();
    }

    /**
     * ユーザー情報と部屋所属情報をまとめて保存する。
     *
     * @param mixed  $entity     MUserInfo エンティティ（patchEntity 済み）
     * @param array  $groupData  MUserGroup の入力データ配列（'i_id_room' を含む）
     * @param string $createdBy  作成者ユーザー名
     * @param int    $actorId    操作者ユーザーID
     * @param string $ipAddress  操作元IPアドレス
     * @return bool
     */
    public function saveWithRooms(\Cake\ORM\Entity $entity, array $groupData, string $createdBy, int $actorId = 0, string $ipAddress = ''): bool
    {
        $userInfoTable  = TableRegistry::getTableLocator()->get('MUserInfo');
        $userGroupTable = TableRegistry::getTableLocator()->get('MUserGroup');

        if (!$userInfoTable->save($entity)) {
            AuditLogService::record('user', 'user_create', $createdBy, $actorId, 'm_user_info', null, ['error' => 'save failed'], $ipAddress ?: null, 0);
            return false;
        }

        $userId     = $entity->i_id_user;
        $userGroups = [];

        foreach ($groupData as $group) {
            if (!empty($group['i_id_room'])) {
                $userGroups[] = $userGroupTable->newEntity([
                    'i_id_user'     => (int)$userId,
                    'i_id_room'     => (int)$group['i_id_room'],
                    'active_flag'   => 0,
                    'dt_create'     => date('Y-m-d H:i:s'),
                    'c_create_user' => $createdBy,
                ]);
            }
        }

        if (!empty($userGroups)) {
            $userGroupTable->saveMany($userGroups);
        }

        AuditLogService::record(
            'user',
            'user_create',
            $createdBy,
            $actorId,
            'm_user_info',
            (string)$userId,
            ['user_name' => $entity->c_user_name, 'login_account' => $entity->c_login_account],
            $ipAddress ?: null,
            1
        );

        return true;
    }
}
