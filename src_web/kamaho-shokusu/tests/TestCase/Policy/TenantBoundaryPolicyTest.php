<?php
declare(strict_types=1);

namespace App\Test\TestCase\Policy;

use App\Model\Entity\MRoomInfo;
use App\Model\Entity\MUserInfo;
use App\Policy\MRoomInfoPolicy;
use App\Policy\MUserInfoPolicy;
use Cake\ORM\Entity;
use Cake\TestSuite\TestCase;

/**
 * テナント越境アクセス防止テスト
 *
 * PolicyTrait::isSameTenant() が各 Policy で正しく機能することを検証する。
 *
 * テスト観点:
 *   - 同一テナントのリソースにはアクセスできる
 *   - 異なるテナントのリソースには管理者でもアクセスできない
 *   - tenant_id が null のユーザーは移行期間として通過する
 *   - tenant_id が null のリソースは移行期間として通過する
 *   - system_admin は isSameTenant を呼ばないため別途確認
 */
class TenantBoundaryPolicyTest extends TestCase
{
    // ----------------------------------------------------------------
    // MRoomInfoPolicy — 部屋データのテナント越境テスト
    // ----------------------------------------------------------------

    private MRoomInfoPolicy $roomPolicy;

    protected function setUp(): void
    {
        parent::setUp();
        $this->roomPolicy = new MRoomInfoPolicy();
    }

    public function testCanView_sameTenant_allowed(): void
    {
        $user     = new PolicyTestIdentity(['i_id_user' => 1, 'i_admin' => 1, 'tenant_id' => 1]);
        $resource = $this->makeRoom(tenantId: 1);

        $this->assertTrue($this->roomPolicy->canView($user, $resource));
    }

    public function testCanView_differentTenant_denied(): void
    {
        $user     = new PolicyTestIdentity(['i_id_user' => 1, 'i_admin' => 1, 'tenant_id' => 1]);
        $resource = $this->makeRoom(tenantId: 2);

        $this->assertFalse($this->roomPolicy->canView($user, $resource));
    }

    public function testCanEdit_differentTenant_denied(): void
    {
        $user     = new PolicyTestIdentity(['i_id_user' => 1, 'i_admin' => 1, 'tenant_id' => 1]);
        $resource = $this->makeRoom(tenantId: 2);

        $this->assertFalse($this->roomPolicy->canEdit($user, $resource));
    }

    public function testCanDelete_differentTenant_denied(): void
    {
        $user     = new PolicyTestIdentity(['i_id_user' => 1, 'i_admin' => 1, 'tenant_id' => 1]);
        $resource = $this->makeRoom(tenantId: 2);

        $this->assertFalse($this->roomPolicy->canDelete($user, $resource));
    }

    public function testCanView_userTenantNull_allowedAsMigrationPeriod(): void
    {
        // tenant_id が null のユーザーは移行期間として許可（後方互換）
        $user     = new PolicyTestIdentity(['i_id_user' => 1, 'i_admin' => 1, 'tenant_id' => null]);
        $resource = $this->makeRoom(tenantId: 2);

        $this->assertTrue($this->roomPolicy->canView($user, $resource));
    }

    public function testCanView_resourceTenantNull_allowedAsMigrationPeriod(): void
    {
        // リソースの tenant_id が null の場合も移行期間として許可
        $user     = new PolicyTestIdentity(['i_id_user' => 1, 'i_admin' => 1, 'tenant_id' => 1]);
        $resource = $this->makeRoom(tenantId: null);

        $this->assertTrue($this->roomPolicy->canView($user, $resource));
    }

    // ----------------------------------------------------------------
    // MUserInfoPolicy — ユーザーデータのテナント越境テスト
    // ----------------------------------------------------------------

    public function testUserCanView_sameTenant_allowed(): void
    {
        $policy   = new MUserInfoPolicy();
        $user     = new PolicyTestIdentity(['i_id_user' => 1, 'i_admin' => 1, 'tenant_id' => 1]);
        $resource = $this->makeUser(userId: 99, tenantId: 1);

        $this->assertTrue($policy->canView($user, $resource));
    }

    public function testUserCanView_differentTenant_denied(): void
    {
        $policy   = new MUserInfoPolicy();
        $user     = new PolicyTestIdentity(['i_id_user' => 1, 'i_admin' => 1, 'tenant_id' => 1]);
        $resource = $this->makeUser(userId: 99, tenantId: 2);

        $this->assertFalse($policy->canView($user, $resource));
    }

    public function testUserCanEdit_differentTenant_denied(): void
    {
        $policy   = new MUserInfoPolicy();
        $user     = new PolicyTestIdentity(['i_id_user' => 1, 'i_admin' => 1, 'tenant_id' => 1]);
        $resource = $this->makeUser(userId: 99, tenantId: 2);

        $this->assertFalse($policy->canEdit($user, $resource));
    }

    public function testUserCanDelete_differentTenant_denied(): void
    {
        $policy   = new MUserInfoPolicy();
        $user     = new PolicyTestIdentity(['i_id_user' => 1, 'i_admin' => 1, 'tenant_id' => 1]);
        $resource = $this->makeUser(userId: 99, tenantId: 2);

        $this->assertFalse($policy->canDelete($user, $resource));
    }

    // ----------------------------------------------------------------
    // テナント管理者(TENANT_ADMIN=4) の越境テスト
    // ----------------------------------------------------------------

    public function testRoomCanView_tenantAdmin_sameTenant_allowed(): void
    {
        $user     = new PolicyTestIdentity(['i_id_user' => 1, 'i_admin' => 4, 'tenant_id' => 1]);
        $resource = $this->makeRoom(tenantId: 1);

        $this->assertTrue($this->roomPolicy->canView($user, $resource));
    }

    public function testRoomCanView_tenantAdmin_differentTenant_denied(): void
    {
        $user     = new PolicyTestIdentity(['i_id_user' => 1, 'i_admin' => 4, 'tenant_id' => 1]);
        $resource = $this->makeRoom(tenantId: 2);

        $this->assertFalse($this->roomPolicy->canView($user, $resource));
    }

    // ----------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------

    private function makeRoom(?int $tenantId): MRoomInfo
    {
        $room = new MRoomInfo();
        $room->set('tenant_id', $tenantId, ['guard' => false]);
        return $room;
    }

    private function makeUser(int $userId, ?int $tenantId): MUserInfo
    {
        $user = new MUserInfo();
        $user->set('i_id_user', $userId, ['guard' => false]);
        $user->set('tenant_id', $tenantId, ['guard' => false]);
        return $user;
    }
}
