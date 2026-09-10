<?php
declare(strict_types=1);

namespace App\Test\TestCase\Policy;

use App\Model\Entity\MMealPriceInfo;
use App\Policy\MMealPriceInfoPolicy;
use Cake\TestSuite\TestCase;

class MMealPriceInfoPolicyTest extends TestCase
{
    /**
     * 単価・控除表は全職員の給与控除額を含むため、閲覧は管理者限定であること。
     */
    public function testCanViewRequiresAdmin(): void
    {
        $policy = new MMealPriceInfoPolicy();
        $resource = new MMealPriceInfo();

        $this->assertFalse($policy->canView(null, $resource));
        $this->assertFalse(
            $policy->canView(new PolicyTestIdentity(['i_id_user' => 1, 'i_admin' => 0]), $resource),
            '一般ユーザーが単価情報を閲覧できてはならない'
        );
        $this->assertFalse(
            $policy->canView(new PolicyTestIdentity(['i_id_user' => 2, 'i_admin' => 2]), $resource),
            'ブロック長が単価情報を閲覧できてはならない'
        );
        $this->assertTrue($policy->canView(new PolicyTestIdentity(['i_id_user' => 3, 'i_admin' => 1]), $resource));
        $this->assertTrue($policy->canView(new PolicyTestIdentity(['i_id_user' => 4, 'i_admin' => 3]), $resource));
    }

    /**
     * 控除表エクスポート（canIndex で認可）も管理者限定であること。
     */
    public function testCanIndexRequiresAdmin(): void
    {
        $policy = new MMealPriceInfoPolicy();
        $resource = new MMealPriceInfo();

        $this->assertFalse($policy->canIndex(null, $resource));
        $this->assertFalse(
            $policy->canIndex(new PolicyTestIdentity(['i_id_user' => 1, 'i_admin' => 0]), $resource),
            '一般ユーザーが控除表をエクスポートできてはならない'
        );
        $this->assertTrue($policy->canIndex(new PolicyTestIdentity(['i_id_user' => 3, 'i_admin' => 1]), $resource));
    }

    public function testCanDeleteRequiresAdmin(): void
    {
        $policy = new MMealPriceInfoPolicy();
        $resource = new MMealPriceInfo();

        $admin = new PolicyTestIdentity(['i_id_user' => 1, 'i_admin' => 1]);
        $nonAdmin = new PolicyTestIdentity(['i_id_user' => 2, 'i_admin' => 0]);

        $this->assertTrue($policy->canDelete($admin, $resource));
        $this->assertFalse($policy->canDelete($nonAdmin, $resource));
    }
}
