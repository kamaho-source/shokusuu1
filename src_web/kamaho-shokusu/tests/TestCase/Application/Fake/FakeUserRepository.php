<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\Fake;

use App\Domain\Repository\UserRepositoryInterface;
use App\Domain\ValueObject\UserRole;
use App\Domain\ValueObject\UserRoleInfo;

/**
 * テスト用の利用者 Repository。
 */
final class FakeUserRepository implements UserRepositoryInterface
{
    /**
     * @param array<int, UserRoleInfo> $users
     * @param array<int, string>       $names 利用者ID => 利用者名
     */
    public function __construct(public array $users = [], public array $names = [])
    {
    }

    public static function staff(int $admin = UserRole::GENERAL): UserRoleInfo
    {
        return new UserRoleInfo($admin, 0);
    }

    public static function child(): UserRoleInfo
    {
        return new UserRoleInfo(UserRole::GENERAL, 1);
    }

    public function findRoleInfo(int $userId): ?UserRoleInfo
    {
        return $this->users[$userId] ?? null;
    }

    public function findRoleInfos(array $userIds): array
    {
        return array_intersect_key($this->users, array_flip($userIds));
    }

    public function findNames(array $userIds): array
    {
        return array_intersect_key($this->names, array_flip($userIds));
    }
}
