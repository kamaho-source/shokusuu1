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

    public function testRegisterAllowedForStaffAndBlockLeaderButNotChildOrUnset(): void
    {
        $policy = new MUserInfoPolicy();
        $resource = new MUserInfo();

        foreach (['add', 'import', 'importForm', 'importJson'] as $action) {
            $method = 'can' . ucfirst($action);
            $this->assertTrue($policy->$method(new PolicyTestIdentity(['i_admin' => 1]), $resource), "$action admin");
            $this->assertTrue($policy->$method(new PolicyTestIdentity(['i_admin' => 2, 'i_user_level' => 1]), $resource), "$action block leader");
            $this->assertTrue($policy->$method(new PolicyTestIdentity(['i_admin' => 0, 'i_user_level' => 0]), $resource), "$action staff");
            $this->assertFalse($policy->$method(new PolicyTestIdentity(['i_admin' => 0, 'i_user_level' => 1]), $resource), "$action child");
            $this->assertFalse($policy->$method(new PolicyTestIdentity(['i_admin' => 0]), $resource), "$action level unset");
            $this->assertFalse($policy->$method(new PolicyTestIdentity(['i_admin' => null, 'i_user_level' => null]), $resource), "$action null");
        }
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

    public function testCanChangeRoleOnlyForAdminOnOthers(): void
    {
        $policy = new MUserInfoPolicy(new TestRoomAccessService([]));
        $target = new MUserInfo();
        $target->set('i_id_user', 10, ['guard' => false]);

        $this->assertTrue($policy->canChangeRole(new PolicyTestIdentity(['i_id_user' => 1, 'i_admin' => 1]), $target));
        $this->assertTrue($policy->canChangeRole(new PolicyTestIdentity(['i_id_user' => 1, 'i_admin' => 3]), $target));
        // 自分自身の役職は、管理者でも変更できない
        $this->assertFalse($policy->canChangeRole(new PolicyTestIdentity(['i_id_user' => 10, 'i_admin' => 1]), $target));
        $this->assertFalse($policy->canChangeRole(new PolicyTestIdentity(['i_id_user' => 2, 'i_admin' => 2, 'i_user_level' => 0]), $target));
        $this->assertFalse($policy->canChangeRole(new PolicyTestIdentity(['i_id_user' => 2, 'i_admin' => 0, 'i_user_level' => 0]), $target));
    }

    /**
     * 職員・ブロック長は、同じ部屋に所属する児童だけ閲覧・編集できる。
     */
    public function testStaffAndBlockLeaderCanManageChildInSharedRoom(): void
    {
        $policy = new MUserInfoPolicy(new TestRoomAccessService([2 => [1], 3 => [1], 4 => [2], 20 => [1]]));
        $child = new MUserInfo();
        $child->set(['i_id_user' => 20, 'i_user_level' => 1], ['guard' => false]);
        $staffTarget = new MUserInfo();
        $staffTarget->set(['i_id_user' => 30, 'i_user_level' => 0], ['guard' => false]);

        $staff   = new PolicyTestIdentity(['i_id_user' => 2, 'i_admin' => 0, 'i_user_level' => 0]);
        $leader  = new PolicyTestIdentity(['i_id_user' => 3, 'i_admin' => 2, 'i_user_level' => 0]);
        $farStaff = new PolicyTestIdentity(['i_id_user' => 4, 'i_admin' => 0, 'i_user_level' => 0]);
        $otherRole = new PolicyTestIdentity(['i_id_user' => 2, 'i_admin' => 0, 'i_user_level' => 3]);
        $childActor = new PolicyTestIdentity(['i_id_user' => 2, 'i_admin' => 0, 'i_user_level' => 1]);

        foreach (['canEdit', 'canView'] as $method) {
            $this->assertTrue($policy->$method($staff, $child), "$method staff");
            $this->assertTrue($policy->$method($leader, $child), "$method block leader");
            $this->assertFalse($policy->$method($farStaff, $child), "$method no shared room");
            $this->assertFalse($policy->$method($otherRole, $child), "$method other role");
            $this->assertFalse($policy->$method($childActor, $child), "$method child actor");
            $this->assertFalse($policy->$method($staff, $staffTarget), "$method non-child target");
        }
    }
}
