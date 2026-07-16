<?php
declare(strict_types=1);

namespace App\Test\TestCase\Policy;

use App\Controller\ContactsController;
use App\Policy\ContactsPolicy;
use Cake\TestSuite\TestCase;

/**
 * ContactsPolicy テスト
 *
 * - canIndex: 未ログインを含む全ユーザーを許可する（LPからの問い合わせ導線）
 * - canAdminIndex / canAdminDetail: 管理者（i_admin = 1 or 3）のみ許可する
 */
class ContactsPolicyTest extends TestCase
{
    private ContactsPolicy $policy;
    private ContactsController $resource;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy   = new ContactsPolicy();
        $this->resource = $this->createMock(ContactsController::class);
    }

    // ----------------------------------------------------------------
    // canIndex
    // ----------------------------------------------------------------

    /**
     * @dataProvider authenticatedUserProvider
     */
    public function testCanIndex_authenticated_allowed(int $adminLevel): void
    {
        $user = new PolicyTestIdentity(['i_admin' => $adminLevel]);
        $this->assertTrue($this->policy->canIndex($user, $this->resource));
    }

    public function testCanIndex_nullUser_allowed(): void
    {
        $this->assertTrue($this->policy->canIndex(null, $this->resource));
    }

    // ----------------------------------------------------------------
    // canAdminIndex
    // ----------------------------------------------------------------

    /**
     * @dataProvider adminUserProvider
     */
    public function testCanAdminIndex_admin_allowed(int $adminLevel): void
    {
        $user = new PolicyTestIdentity(['i_admin' => $adminLevel]);
        $this->assertTrue($this->policy->canAdminIndex($user, $this->resource));
    }

    /**
     * @dataProvider nonAdminUserProvider
     */
    public function testCanAdminIndex_nonAdmin_denied(int $adminLevel): void
    {
        $user = new PolicyTestIdentity(['i_admin' => $adminLevel]);
        $this->assertFalse($this->policy->canAdminIndex($user, $this->resource));
    }

    public function testCanAdminIndex_nullUser_denied(): void
    {
        $this->assertFalse($this->policy->canAdminIndex(null, $this->resource));
    }

    // ----------------------------------------------------------------
    // canAdminDetail
    // ----------------------------------------------------------------

    /**
     * @dataProvider adminUserProvider
     */
    public function testCanAdminDetail_admin_allowed(int $adminLevel): void
    {
        $user = new PolicyTestIdentity(['i_admin' => $adminLevel]);
        $this->assertTrue($this->policy->canAdminDetail($user, $this->resource));
    }

    /**
     * @dataProvider nonAdminUserProvider
     */
    public function testCanAdminDetail_nonAdmin_denied(int $adminLevel): void
    {
        $user = new PolicyTestIdentity(['i_admin' => $adminLevel]);
        $this->assertFalse($this->policy->canAdminDetail($user, $this->resource));
    }

    public function testCanAdminDetail_nullUser_denied(): void
    {
        $this->assertFalse($this->policy->canAdminDetail(null, $this->resource));
    }

    // ----------------------------------------------------------------
    // DataProviders
    // ----------------------------------------------------------------

    public static function authenticatedUserProvider(): array
    {
        return [
            'general (0)'      => [0],
            'admin (1)'        => [1],
            'block_leader (2)' => [2],
            'system_admin (3)' => [3],
        ];
    }

    public static function adminUserProvider(): array
    {
        return [
            'admin (1)'        => [1],
            'system_admin (3)' => [3],
        ];
    }

    public static function nonAdminUserProvider(): array
    {
        return [
            'general (0)'      => [0],
            'block_leader (2)' => [2],
        ];
    }
}
