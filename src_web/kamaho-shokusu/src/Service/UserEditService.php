<?php
declare(strict_types=1);

namespace App\Service;

use Cake\ORM\TableRegistry;
use Cake\I18n\DateTime;

/**
 * ユーザー編集サービス
 *
 * トランザクションを管理しながら、ユーザー情報と部屋所属情報を差し替え保存する。
 */
class UserEditService
{
    /**
     * 監査ログに変更前→変更後を残す項目。
     */
    private const AUDITED_FIELDS = ['c_login_account', 'c_user_name', 'i_user_gender', 'i_user_rank', 'i_user_level', 'i_id_staff'];

    /**
     * @param \App\Model\Entity\MUserInfo $entity    MUserInfo エンティティ（contain MUserGroup 済み）
     * @param array  $data      リクエストデータ
     * @param int[]  $roomIds   新しく所属させる部屋IDの配列
     * @param string $updatedBy 操作者ユーザー名
     * @param int    $actorId   操作者ユーザーID
     * @param string $ipAddress 操作元IPアドレス
     * @param string $actorLoginId 操作者ログインID
     * @param list<string>|null $fields 更新を許す項目。null は従来どおり制限なし
     * @return bool
     * @throws \Exception
     */
    public function updateWithRooms(\App\Model\Entity\MUserInfo $entity, array $data, array $roomIds, string $updatedBy, int $actorId = 0, string $ipAddress = '', string $actorLoginId = '', ?array $fields = null): bool
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
                'dt_create'     => DateTime::now()->format('Y-m-d H:i:s'),
                'dt_update'     => DateTime::now()->format('Y-m-d H:i:s'),
                'c_update_user' => $updatedBy,
            ]);
        }

        $conn = $userInfoTable->getConnection();
        $conn->begin();

        try {
            // 削除するのは「現在所属している行(active_flag=0)」と、今回選択された部屋の行だけ。
            // 部屋異動で無効化された旧部屋の行(active_flag=1)まで消して作り直すと、
            // 保存のたびに旧部屋の所属が復活して食数が二重になる。
            $deleteConditions = ['active_flag' => 0];
            if ($roomIds !== []) {
                $deleteConditions = ['OR' => [
                    'active_flag'  => 0,
                    'i_id_room IN' => array_map('intval', $roomIds),
                ]];
            }
            $userGroupTable->deleteAll(['i_id_user' => $userId] + $deleteConditions);

            $before = $entity->extract(self::AUDITED_FIELDS);
            $options = ['associated' => ['MUserGroup']];
            if ($fields !== null) {
                $options['fields'] = $fields;
            }
            $entity = $userInfoTable->patchEntity($entity, $data, $options);
            $changes = $this->diff($before, $entity->extract(self::AUDITED_FIELDS));
            $entity->m_user_group = $newUserGroups;

            if (!$userInfoTable->save($entity, ['associated' => ['MUserGroup']])) {
                $conn->rollback();
                return false;
            }

            $conn->commit();

            AuditLogService::record(
                'user',
                'user_update',
                $updatedBy,
                $actorId,
                'm_user_info',
                (string)$userId,
                ['user_name' => $entity->c_user_name] + ($changes === [] ? [] : ['changes' => $changes]),
                $ipAddress ?: null,
                1,
                $actorLoginId
            );

            return true;
        } catch (\Exception $e) {
            $conn->rollback();

            AuditLogService::record(
                'user',
                'user_update',
                $updatedBy,
                $actorId,
                'm_user_info',
                (string)$userId,
                ['error' => $e->getMessage()],
                $ipAddress ?: null,
                0,
                $actorLoginId
            );

            throw $e;
        }
    }

    /**
     * 変わった項目だけを [項目 => [変更前, 変更後]] で返す。
     *
     * @param array<string, int|string|null> $before
     * @param array<string, int|string|null> $after
     * @return array<string, array{0: int|string|null, 1: int|string|null}>
     */
    private function diff(array $before, array $after): array
    {
        $changes = [];
        foreach ($after as $field => $value) {
            if ((string)($before[$field] ?? '') !== (string)($value ?? '')) {
                $changes[$field] = [$before[$field] ?? null, $value];
            }
        }

        return $changes;
    }
}
