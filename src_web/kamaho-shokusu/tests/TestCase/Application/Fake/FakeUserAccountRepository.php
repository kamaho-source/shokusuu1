<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\Fake;

use App\Domain\Entity\UserAccount;
use App\Domain\Entity\UserCreationResult;
use App\Domain\Entity\UserUpdateResult;
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

    /** @var list<string> 使われているログインID */
    public array $loginAccounts = [];

    /** @var list<array{array<string, mixed>, array<array-key, mixed>, list<int>, string}> create の呼び出し */
    public array $created = [];

    public function loginAccountExists(string $loginAccount): bool
    {
        return in_array($loginAccount, $this->loginAccounts, true);
    }

    public function nextDisplayNo(): int
    {
        return 100;
    }

    public function create(array $attributes, array $input, array $roomIds, string $createdBy): UserCreationResult
    {
        if ($this->failSave) {
            return UserCreationResult::saveFailed();
        }
        $this->created[] = [$attributes, $input, $roomIds, $createdBy];

        return UserCreationResult::created(500 + count($this->created));
    }

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

    /** @var array<int, list<int>> 利用者ID => 削除で外した部屋（roomsLeftByDeletion が返す） */
    public array $leftByDeletion = [];

    /** 削除のための所属の退出を失敗させる */
    public bool $failLeaveForDeletion = false;

    /** @var list<string> markDeleted に渡された時刻 */
    public array $deletedAt = [];

    public function markDeleted(int $userId, string $updatedBy, ?string $at = null): bool
    {
        $this->deletedAt[] = (string)$at;
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

    public function leaveAllRoomsForDeletion(int $userId, string $updatedBy, string $at): bool
    {
        $this->roomOps[] = 'leaveForDeletion:' . $userId . '@' . $at;

        return !$this->failLeaveForDeletion;
    }

    public function roomsLeftByDeletion(int $userId): array
    {
        return $this->leftByDeletion[$userId] ?? [];
    }

    public function freezeLeftByDeletion(int $userId): void
    {
        $this->roomOps[] = 'freeze:' . $userId;
    }

    public function rejoinRooms(int $userId, array $roomIds, string $updatedBy): bool
    {
        $this->roomOps[] = 'rejoin:' . $userId . ':' . implode(',', $roomIds);

        return !$this->failRooms;
    }

    public function joinRoom(int $userId, int $roomId, string $createdBy): bool
    {
        $this->roomOps[] = 'join:' . $userId . ':' . $roomId;

        return !$this->failRooms;
    }

    /** @var list<array{int, array<string, mixed>, list<string>}> updateProfile の呼び出し [利用者ID, 値, 変えてよい項目] */
    public array $profileUpdates = [];

    /** updateProfile で例外を投げる */
    public ?\Throwable $profileThrows = null;

    public function updateProfile(int $userId, array $data, array $fields): UserUpdateResult
    {
        if ($this->profileThrows !== null) {
            throw $this->profileThrows;
        }
        if ($this->failSave) {
            return UserUpdateResult::failed(['c_user_name' => ['unique' => 'このユーザー名は既に使用されています。']]);
        }
        $this->profileUpdates[] = [$userId, $data, $fields];
        $before = ['c_user_name' => $this->users[$userId]->name ?? null];

        return UserUpdateResult::saved($before, ['c_user_name' => $data['c_user_name'] ?? null]);
    }

    public function replaceActiveRooms(int $userId, array $roomIds, string $updatedBy): bool
    {
        $this->roomOps[] = 'replace:' . $userId . ':' . implode(',', $roomIds);

        return !$this->failRooms;
    }

    /** @var array<int, string> changePassword の呼び出し（利用者ID => 新しいパスワード） */
    public array $passwords = [];

    public function activeUserNames(): array
    {
        $names = [];
        foreach ($this->users as $u) {
            if ($u->delFlag === 0) {
                $names[$u->id] = (string)$u->name;
            }
        }

        return $names;
    }

    public function changePassword(int $userId, string $plainPassword): bool
    {
        if ($this->failSave || !isset($this->users[$userId])) {
            return false;
        }
        $this->passwords[$userId] = $plainPassword;

        return true;
    }

    /** @var list<array<string, mixed>> importUser の呼び出し */
    public array $imported = [];

    /** importUser で例外を投げる */
    public ?\Throwable $importThrows = null;

    public function importUser(array $data): UserCreationResult
    {
        if ($this->importThrows !== null) {
            throw $this->importThrows;
        }
        if ($this->failSave) {
            return UserCreationResult::saveFailed();
        }
        $this->imported[] = $data;

        return UserCreationResult::created(700 + count($this->imported));
    }

    private function setDelFlag(int $userId, int $flag): bool
    {
        if ($this->failSave || !isset($this->users[$userId])) {
            return false;
        }
        $u = $this->users[$userId];
        $this->users[$userId] = new UserAccount($u->id, $u->name, $u->role, $u->reportAccess, $flag, $u->level, $u->loginAccount);

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
            $u->level,
            $u->loginAccount,
        );

        return true;
    }
}
