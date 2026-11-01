<?php
declare(strict_types=1);

namespace App\Infrastructure\Repository;

use App\Domain\Entity\UserAccount;
use App\Domain\Repository\UserAccountRepositoryInterface;
use Cake\Datasource\EntityInterface;
use Cake\I18n\DateTime;
use Cake\ORM\Table;

/**
 * UserAccountRepositoryInterface の CakePHP 実装（m_user_info）。
 *
 * 保存はテーブルの save() を通す（従来どおり、ルール・イベントを通すため）。
 */
final class CakeUserAccountRepository implements UserAccountRepositoryInterface
{
    public function __construct(private readonly Table $users)
    {
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

    /**
     * @param array<string, mixed> $fields
     */
    private function update(int $userId, array $fields, string $updatedBy): bool
    {
        $row = $this->row($userId);
        if ($row === null) {
            return false;
        }
        foreach ($fields + [
            'dt_update'     => DateTime::now()->format('Y-m-d H:i:s'),
            'c_update_user' => $updatedBy,
        ] as $field => $value) {
            $row->set($field, $value);
        }

        return (bool)$this->users->save($row);
    }

    private function row(int $userId): ?EntityInterface
    {
        /** @var \Cake\Datasource\EntityInterface|null */
        return $this->users->find()->where(['i_id_user' => $userId])->first();
    }
}
