<?php
declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Entity\UserAccount;
use App\Domain\Repository\UserAccountRepositoryInterface;
use Cake\Datasource\EntityInterface;
use Cake\I18n\DateTime;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;

/**
 * UserAccountRepositoryInterface の CakePHP 実装（m_user_info）。
 *
 * 保存はテーブルの save() を通す（従来どおり、ルール・イベントを通すため）。
 */
final class CakeUserAccountRepository implements UserAccountRepositoryInterface
{
    private readonly Table $groups;

    public function __construct(private readonly Table $users, ?Table $groups = null)
    {
        $this->groups = $groups ?? TableRegistry::getTableLocator()->get('MUserGroup');
    }

    public function find(int $userId): ?UserAccount
    {
        $row = $this->row($userId);
        if ($row === null) {
            return null;
        }

        return new UserAccount(
            (int)$row->get('i_id_user'),
            $row->get('c_user_name') === null ? null : (string)$row->get('c_user_name'),
            $row->get('i_admin') === null ? null : (int)$row->get('i_admin'),
            $row->get('i_report_access') === null ? null : (int)$row->get('i_report_access'),
            $row->get('i_del_flag') === null ? null : (int)$row->get('i_del_flag'),
        );
    }

    public function updateRole(int $userId, int $role, string $updatedBy): bool
    {
        return $this->update($userId, ['i_admin' => $role], $updatedBy);
    }

    public function updateReportAccess(int $userId, int $reportAccess, string $updatedBy): bool
    {
        return $this->update($userId, ['i_report_access' => $reportAccess], $updatedBy);
    }

    public function markDeleted(int $userId, string $updatedBy): bool
    {
        return $this->update($userId, ['i_del_flag' => 1, 'i_enable' => 1], $updatedBy);
    }

    public function markRestored(int $userId, string $updatedBy): bool
    {
        // 削除時に立てた無効化（i_enable=1）も戻す（ログインできるようにする）
        return $this->update($userId, ['i_del_flag' => 0, 'i_enable' => 0], $updatedBy);
    }

    public function leaveAllRooms(int $userId, string $updatedBy): void
    {
        $rows = $this->groups->find()->where(['i_id_user' => $userId, 'active_flag' => 0])->all();
        foreach ($rows as $row) {
            $this->setUpdated($row, ['active_flag' => 1], $updatedBy);
            $this->groups->save($row);
        }
    }

    public function rejoinAllRooms(int $userId, string $updatedBy): bool
    {
        $rows = $this->groups->find()->where(['i_id_user' => $userId])->all();
        foreach ($rows as $row) {
            $this->setUpdated($row, ['active_flag' => 0], $updatedBy);
            if (!$this->groups->save($row)) {
                return false;
            }
        }

        return true;
    }

    public function joinRoom(int $userId, int $roomId, string $createdBy): bool
    {
        $row = $this->groups->newEntity([
            'i_id_user'     => $userId,
            'i_id_room'     => $roomId,
            'active_flag'   => 0,
            'dt_create'     => DateTime::now()->format('Y-m-d H:i:s'),
            'c_create_user' => $createdBy,
        ]);

        return (bool)$this->groups->save($row);
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function setUpdated(EntityInterface $row, array $fields, string $updatedBy): void
    {
        foreach ($fields + [
            'dt_update'     => DateTime::now()->format('Y-m-d H:i:s'),
            'c_update_user' => $updatedBy,
        ] as $field => $value) {
            $row->set($field, $value);
        }
    }

    /**
     * @param array<string, mixed> $fields
     */
    private function update(int $userId, array $fields, string $updatedBy): bool
    {
        $row = $this->row($userId);
        if ($row === null) {
            return false;
        }
        $this->setUpdated($row, $fields, $updatedBy);

        return (bool)$this->users->save($row);
    }

    private function row(int $userId): ?EntityInterface
    {
        /** @var \Cake\Datasource\EntityInterface|null */
        return $this->users->find()->where(['i_id_user' => $userId])->first();
    }
}
