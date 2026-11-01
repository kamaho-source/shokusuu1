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
    /** @param array<int, UserRoleInfo> $users */
    public function __construct(public array $users = [])
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
}
