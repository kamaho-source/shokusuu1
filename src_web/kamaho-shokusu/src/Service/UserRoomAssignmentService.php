<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\TableRegistry;

/**
 * ユーザー部屋割り当てサービス
 *
 * 既存の所属（active_flag=0）を無効化し、指定部屋名で新規所属を登録する。
 * 処理はトランザクション内で実行される。最大2部屋まで。
 */
class UserRoomAssignmentService
{
    /**
     * @param int      $userId    対象ユーザーID
     * @param string[] $roomNames 部屋名の配列（最大2件）
     * @param string   $actor     操作者ユーザー名
     * @return array{created: int, errors: string[]}
     * @throws \Throwable
     */
    public function assign(int $userId, array $roomNames, string $actor): array
    {
        $userGroupTable = TableRegistry::getTableLocator()->get('MUserGroup');
        $roomInfoTable  = TableRegistry::getTableLocator()->get('MRoomInfo');

        $roomNames = array_slice($roomNames, 0, 2);
        $errors    = [];
        $created   = 0;

        $conn = $userGroupTable->getConnection();
        $conn->begin();

        try {
            $oldGroups = $userGroupTable->find()
                ->where(['i_id_user' => $userId, 'active_flag' => 0])
                ->all();

            foreach ($oldGroups as $group) {
                $group->active_flag   = 1;
                $group->dt_update     = date('Y-m-d H:i:s');
                $group->c_update_user = $actor;
                $userGroupTable->save($group);
            }

            foreach ($roomNames as $roomName) {
                $room = $roomInfoTable->find()->where(['c_room_name' => $roomName])->first();
                if ($room) {
                    $newGroup = $userGroupTable->newEntity([
                        'i_id_user'     => $userId,
                        'i_id_room'     => $room->i_id_room,
                        'active_flag'   => 0,
                        'dt_create'     => date('Y-m-d H:i:s'),
                        'c_create_user' => $actor,
                    ]);
                    if ($userGroupTable->save($newGroup)) {
                        $created++;
                    } else {
                        $errors[] = "部屋 '{$roomName}' の登録に失敗";
                    }
                } else {
                    $errors[] = "部屋名 '{$roomName}' が見つかりません";
                }
            }

            $conn->commit();
        } catch (\Throwable $e) {
            $conn->rollback();
            throw $e;
        }

        return ['created' => $created, 'errors' => $errors];
    }
}
