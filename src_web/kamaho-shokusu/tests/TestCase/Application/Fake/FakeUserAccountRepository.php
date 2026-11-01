<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\Fake;

use App\Domain\Entity\UserAccount;
use App\Domain\Repository\UserAccountRepositoryInterface;

/**
 * UserAccountRepositoryInterface の Fake（メモリ上）。
 */
final class FakeUserAccountRepository implements UserAccountRepositoryInterface
{
    /** @var array<int, UserAccount> */
    public array $users = [];

    /** 保存を失敗させる */
    public bool $failSave = false;

    /** @var list<array{string, int, int, string}> [項目, 利用者ID, 値, 更新者] */
    public array $updates = [];

    public function find(int $userId): ?UserAccount
    {
        return $this->users[$userId] ?? null;
    }

    public function updateRole(int $userId, int $role, string $updatedBy): bool
    {
        return $this->update('role', $userId, $role, $updatedBy);
    }

    public function updateReportAccess(int $userId, int $reportAccess, string $updatedBy): bool
    {
        return $this->update('reportAccess', $userId, $reportAccess, $updatedBy);
    }

    private function update(string $field, int $userId, int $value, string $updatedBy): bool
    {
        if ($this->failSave || !isset($this->users[$userId])) {
            return false;
        }
        $this->updates[] = [$field, $userId, $value, $updatedBy];
        $u = $this->users[$userId];
        $this->users[$userId] = new UserAccount(
            $u->id,
            $u->name,
            $field === 'role' ? $value : $u->role,
            $field === 'reportAccess' ? $value : $u->reportAccess,
        );

        return true;
    }
}
