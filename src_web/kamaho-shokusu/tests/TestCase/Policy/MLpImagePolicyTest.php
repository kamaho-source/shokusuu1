<?php
declare(strict_types=1);

namespace App\Test\TestCase\Policy;

use App\Model\Entity\MLpImage;
use App\Policy\MLpImagePolicy;
use Cake\TestSuite\TestCase;

/**
 * MLpImagePolicy テスト
 *
 * 全アクション（index / add / edit / delete）とも
 * 管理者（i_admin = 1 or 3）のみ許可することを確認する。
 */
class MLpImagePolicyTest extends TestCase
{
    private MLpImagePolicy $policy;
    private MLpImage $resource;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy   = new MLpImagePolicy();
        $this->resource = new MLpImage();
    }

    /**
     * @dataProvider adminUserProvider
     */
    public function testAllActions_admin_allowed(int $adminLevel): void
    {
        $user = new PolicyTestIdentity(['i_admin' => $adminLevel]);
        $this->assertTrue($this->policy->canIndex($user, $this->resource));
        $this->assertTrue($this->policy->canAdd($user, $this->resource));
        $this->assertTrue($this->policy->canEdit($user, $this->resource));
        $this->assertTrue($this->policy->canDelete($user, $this->resource));
    }

    /**
     * @dataProvider nonAdminUserProvider
     */
    public function testAllActions_nonAdmin_denied(int $adminLevel): void
    {
        $user = new PolicyTestIdentity(['i_admin' => $adminLevel]);
        $this->assertFalse($this->policy->canIndex($user, $this->resource));
        $this->assertFalse($this->policy->canAdd($user, $this->resource));
        $this->assertFalse($this->policy->canEdit($user, $this->resource));
        $this->assertFalse($this->policy->canDelete($user, $this->resource));
    }

    public function testAllActions_nullUser_denied(): void
    {
        $this->assertFalse($this->policy->canIndex(null, $this->resource));
        $this->assertFalse($this->policy->canAdd(null, $this->resource));
        $this->assertFalse($this->policy->canEdit(null, $this->resource));
        $this->assertFalse($this->policy->canDelete(null, $this->resource));
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
