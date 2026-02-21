<?php
declare(strict_types=1);

namespace App\Test\TestCase\Policy;

use App\Model\Entity\MUserInfo;
use App\Policy\MUserInfoPolicy;
use Cake\TestSuite\TestCase;

class MUserInfoPolicyTest extends TestCase
{
    public function testCanViewAllowsOwnerAndAdmin(): void
    {
        $policy = new MUserInfoPolicy();
        $resource = new MUserInfo();
        $resource->set('i_id_user', 10, ['guard' => false]);

        $owner = new PolicyTestIdentity(['i_id_user' => 10, 'i_admin' => 0]);
        $admin = new PolicyTestIdentity(['i_id_user' => 1, 'i_admin' => 1]);
        $other = new PolicyTestIdentity(['i_id_user' => 20, 'i_admin' => 0]);

        $this->assertTrue($policy->canView($owner, $resource));
        $this->assertTrue($policy->canView($admin, $resource));
        $this->assertFalse($policy->canView($other, $resource));
    }

    public function testCanDeleteRequiresAdmin(): void
    {
        $policy = new MUserInfoPolicy();
        $resource = new MUserInfo();

        $admin = new PolicyTestIdentity(['i_id_user' => 1, 'i_admin' => 1]);
        $staff = new PolicyTestIdentity(['i_id_user' => 2, 'i_admin' => 0]);

        $this->assertTrue($policy->canDelete($admin, $resource));
        $this->assertFalse($policy->canDelete($staff, $resource));
    }
}
