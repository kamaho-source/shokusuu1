<?php
declare(strict_types=1);

namespace App\Test\TestCase\Policy;

use App\Model\Entity\MRoomInfo;
use App\Policy\MRoomInfoPolicy;
use Cake\TestSuite\TestCase;

class MRoomInfoPolicyTest extends TestCase
{
    public function testCanIndexRequiresAuthentication(): void
    {
        $policy = new MRoomInfoPolicy();
        $resource = new MRoomInfo();

        $this->assertFalse($policy->canIndex(null, $resource));
        $this->assertTrue($policy->canIndex(new PolicyTestIdentity(['i_id_user' => 1]), $resource));
    }

    public function testCanAddRequiresAdmin(): void
    {
        $policy = new MRoomInfoPolicy();
        $resource = new MRoomInfo();

        $admin = new PolicyTestIdentity(['i_id_user' => 1, 'i_admin' => 1]);
        $staff = new PolicyTestIdentity(['i_id_user' => 2, 'i_admin' => 0, 'i_user_level' => 0]);

        $this->assertTrue($policy->canAdd($admin, $resource));
        $this->assertFalse($policy->canAdd($staff, $resource));
    }
}
