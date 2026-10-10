<?php
declare(strict_types=1);

namespace App\Test\TestCase\Policy;

use App\Model\Entity\TContact;
use App\Policy\TContactPolicy;
use Cake\TestSuite\TestCase;

/**
 * TContactPolicy テスト
 *
 * - canIndex: 認証済みユーザー全員を許可する
 * - canAdminIndex / canAdminDetail: 管理者（i_admin = 1 or 3）のみ許可する
 */
class TContactPolicyTest extends TestCase
{
    private TContactPolicy $policy;
    private TContact $resource;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy   = new TContactPolicy();
        $this->resource = $this->createMock(TContact::class);
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

    public function testCanIndex_nullUser_denied(): void
    {
        $this->assertFalse($this->policy->canIndex(null, $this->resource));
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
    // canReply
    // ----------------------------------------------------------------

    public function testCanReply_owner_allowed(): void
    {
        $user = new PolicyTestIdentity(['i_id_user' => 1, 'i_admin' => 0]);
        $contact = new TContact(['user_id' => 1]);

        $this->assertTrue($this->policy->canReply($user, $contact));
    }

    public function testCanReply_otherUser_denied(): void
    {
        $user = new PolicyTestIdentity(['i_id_user' => 2, 'i_admin' => 0]);
        $contact = new TContact(['user_id' => 1]);

        $this->assertFalse($this->policy->canReply($user, $contact));
    }

    public function testCanReply_admin_butNotOwner_denied(): void
    {
        // 管理者であっても、自分の問い合わせでなければ本人用の追記返信は不可（管理者は adminDetail を使う）。
        $user = new PolicyTestIdentity(['i_id_user' => 99, 'i_admin' => 1]);
        $contact = new TContact(['user_id' => 1]);

        $this->assertFalse($this->policy->canReply($user, $contact));
    }

    public function testCanReply_nullUser_denied(): void
    {
        $contact = new TContact(['user_id' => 1]);

        $this->assertFalse($this->policy->canReply(null, $contact));
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
