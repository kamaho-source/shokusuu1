<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\TableRegistry;

/**
 * ユーザー編集サービス
 *
 * トランザクションを管理しながら、ユーザー情報と部屋所属情報を差し替え保存する。
 */
class UserEditService
{
    /**
     * @param mixed  $entity    MUserInfo エンティティ（contain MUserGroup 済み）
     * @param array  $data      リクエストデータ
     * @param int[]  $roomIds   新しく所属させる部屋IDの配列
     * @param string $updatedBy 操作者ユーザー名
     * @return bool
     * @throws \Exception
     */
    public function updateWithRooms(mixed $entity, array $data, array $roomIds, string $updatedBy): bool
    {
        $userInfoTable  = TableRegistry::getTableLocator()->get('MUserInfo');
        $userGroupTable = TableRegistry::getTableLocator()->get('MUserGroup');

        $userId = $entity->i_id_user;

        $newUserGroups = [];
        foreach ($roomIds as $roomId) {
            $newUserGroups[] = $userInfoTable->MUserGroup->newEntity([
                'i_id_user'     => $userId,
                'i_id_room'     => (int)$roomId,
                'active_flag'   => 0,
                'dt_create'     => date('Y-m-d H:i:s'),
                'dt_update'     => date('Y-m-d H:i:s'),
                'c_update_user' => $updatedBy,
            ]);
        }

        $conn = $userInfoTable->getConnection();
        $conn->begin();

        try {
            $userGroupTable->deleteAll(['i_id_user' => $userId]);

            $entity = $userInfoTable->patchEntity($entity, $data, ['associated' => ['MUserGroup']]);
            $entity->m_user_group = $newUserGroups;

            if (!$userInfoTable->save($entity, ['associated' => ['MUserGroup']])) {
                $conn->rollback();
                return false;
            }

            $conn->commit();
            return true;
        } catch (\Exception $e) {
            $conn->rollback();
            throw $e;
        }
    }
}
