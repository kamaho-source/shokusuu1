<?php
declare(strict_types=1);

namespace App\Test\TestCase\Policy;

use App\Model\Entity\MMealPriceInfo;
use App\Policy\MMealPriceInfoPolicy;
use Cake\TestSuite\TestCase;

class MMealPriceInfoPolicyTest extends TestCase
{
    public function testCanViewRequiresAuthentication(): void
    {
        $policy = new MMealPriceInfoPolicy();
        $resource = new MMealPriceInfo();

        $this->assertFalse($policy->canView(null, $resource));
        $this->assertTrue($policy->canView(new PolicyTestIdentity(['i_id_user' => 1]), $resource));
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
