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

    /** @var list<string> 呼ばれた所属の操作（"leave:20" など） */
    public array $roomOps = [];

    /** 所属の操作を失敗させる（rejoin・join） */
    public bool $failRooms = false;

    public function markDeleted(int $userId, string $updatedBy): bool
    {
        return $this->setDelFlag($userId, 1);
    }

    public function markRestored(int $userId, string $updatedBy): bool
    {
        return $this->setDelFlag($userId, 0);
    }

    public function leaveAllRooms(int $userId, string $updatedBy): void
    {
        $this->roomOps[] = 'leave:' . $userId;
    }

    public function rejoinAllRooms(int $userId, string $updatedBy): bool
    {
        $this->roomOps[] = 'rejoin:' . $userId;

        return !$this->failRooms;
    }

    public function joinRoom(int $userId, int $roomId, string $createdBy): bool
    {
        $this->roomOps[] = 'join:' . $userId . ':' . $roomId;

        return !$this->failRooms;
    }

    private function setDelFlag(int $userId, int $flag): bool
    {
        if ($this->failSave || !isset($this->users[$userId])) {
            return false;
        }
        $u = $this->users[$userId];
        $this->users[$userId] = new UserAccount($u->id, $u->name, $u->role, $u->reportAccess, $flag);

        return true;
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
            $u->delFlag,
        );

        return true;
    }
}
