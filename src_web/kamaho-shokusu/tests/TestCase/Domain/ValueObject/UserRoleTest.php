<?php
declare(strict_types=1);

namespace App\Test\TestCase\Domain\ValueObject;

use App\Domain\ValueObject\UserRole;
use Cake\TestSuite\TestCase;

/**
 * UserRole 値オブジェクトのテスト。
 *
 * 定数値とロール判定メソッドが正しく機能することを検証する。
 */
class UserRoleTest extends TestCase
{
    // ----------------------------------------------------------------
    // 定数値
    // ----------------------------------------------------------------

    public function testConstantsHaveExpectedValues(): void
    {
        $this->assertSame(0, UserRole::GENERAL);
        $this->assertSame(1, UserRole::ADMIN);
        $this->assertSame(2, UserRole::BLOCK_LEADER);
        $this->assertSame(3, UserRole::SYSTEM_ADMIN);
        $this->assertSame(4, UserRole::TENANT_ADMIN);
    }

    // ----------------------------------------------------------------
    // isAdmin
    // ----------------------------------------------------------------

    public function testIsAdminReturnsTrueForAdmin(): void
    {
        $this->assertTrue(UserRole::isAdmin(UserRole::ADMIN));
    }

    public function testIsAdminReturnsTrueForSystemAdmin(): void
    {
        $this->assertTrue(UserRole::isAdmin(UserRole::SYSTEM_ADMIN));
    }

    public function testIsAdminReturnsTrueForTenantAdmin(): void
    {
        $this->assertTrue(UserRole::isAdmin(UserRole::TENANT_ADMIN));
    }

    public function testIsAdminReturnsFalseForGeneral(): void
    {
        $this->assertFalse(UserRole::isAdmin(UserRole::GENERAL));
    }

    public function testIsAdminReturnsFalseForBlockLeader(): void
    {
        $this->assertFalse(UserRole::isAdmin(UserRole::BLOCK_LEADER));
    }

    // ----------------------------------------------------------------
    // isBlockLeader
    // ----------------------------------------------------------------

    public function testIsBlockLeaderReturnsTrueForBlockLeader(): void
    {
        $this->assertTrue(UserRole::isBlockLeader(UserRole::BLOCK_LEADER));
    }

    public function testIsBlockLeaderReturnsFalseForGeneral(): void
    {
        $this->assertFalse(UserRole::isBlockLeader(UserRole::GENERAL));
    }

    public function testIsBlockLeaderReturnsFalseForAdmin(): void
    {
        $this->assertFalse(UserRole::isBlockLeader(UserRole::ADMIN));
    }

    public function testIsBlockLeaderReturnsFalseForSystemAdmin(): void
    {
        $this->assertFalse(UserRole::isBlockLeader(UserRole::SYSTEM_ADMIN));
    }

    // ----------------------------------------------------------------
    // isSystemAdmin
    // ----------------------------------------------------------------

    public function testIsSystemAdminReturnsTrueForSystemAdmin(): void
    {
        $this->assertTrue(UserRole::isSystemAdmin(UserRole::SYSTEM_ADMIN));
    }

    public function testIsSystemAdminReturnsFalseForAdmin(): void
    {
        $this->assertFalse(UserRole::isSystemAdmin(UserRole::ADMIN));
    }

    public function testIsSystemAdminReturnsFalseForGeneral(): void
    {
        $this->assertFalse(UserRole::isSystemAdmin(UserRole::GENERAL));
    }

    public function testIsSystemAdminReturnsFalseForBlockLeader(): void
    {
        $this->assertFalse(UserRole::isSystemAdmin(UserRole::BLOCK_LEADER));
    }

    // ----------------------------------------------------------------
    // データプロバイダーを使った網羅テスト
    // ----------------------------------------------------------------

    /**
     * @dataProvider isAdminProvider
     */
    public function testIsAdminExhaustive(int $value, bool $expected): void
    {
        $this->assertSame($expected, UserRole::isAdmin($value));
    }

    public static function isAdminProvider(): array
    {
        return [
            'GENERAL(0) → false'       => [0, false],
            'ADMIN(1) → true'          => [1, true],
            'BLOCK_LEADER(2) → false'  => [2, false],
            'SYSTEM_ADMIN(3) → true'   => [3, true],
            'TENANT_ADMIN(4) → true'   => [4, true],
        ];
    }

    // ----------------------------------------------------------------
    // isTenantAdmin
    // ----------------------------------------------------------------

    public function testIsTenantAdminReturnsTrueForTenantAdmin(): void
    {
        $this->assertTrue(UserRole::isTenantAdmin(UserRole::TENANT_ADMIN));
    }

    public function testIsTenantAdminReturnsFalseForAdmin(): void
    {
        $this->assertFalse(UserRole::isTenantAdmin(UserRole::ADMIN));
    }

    public function testIsTenantAdminReturnsFalseForSystemAdmin(): void
    {
        $this->assertFalse(UserRole::isTenantAdmin(UserRole::SYSTEM_ADMIN));
    }

    public function testIsTenantAdminReturnsFalseForGeneral(): void
    {
        $this->assertFalse(UserRole::isTenantAdmin(UserRole::GENERAL));
    }
}
