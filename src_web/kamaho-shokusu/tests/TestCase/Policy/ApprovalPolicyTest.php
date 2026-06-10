<?php
declare(strict_types=1);

namespace App\Test\TestCase\Policy;

use App\Policy\ApprovalPolicy;
use Authorization\IdentityInterface;
use Authorization\Policy\ResultInterface;
use Cake\TestSuite\TestCase;

class ApprovalPolicyTest extends TestCase
{
    public function testBlockLeaderActionsAllowBlockLeaderAndAdmin(): void
    {
        $policy = new ApprovalPolicy();
        $resource = null;

        $admin = new ApprovalTestIdentity(['i_id_user' => 1, 'i_admin' => 1]);
        $blockLeader = new ApprovalTestIdentity(['i_id_user' => 2, 'i_admin' => 2]);
        $staff = new ApprovalTestIdentity(['i_id_user' => 3, 'i_admin' => 0]);

        $this->assertTrue($policy->canBlockLeaderIndex($admin, $resource));
        $this->assertTrue($policy->canBlockLeaderApprove($admin, $resource));
        $this->assertTrue($policy->canBlockLeaderReject($admin, $resource));

        $this->assertTrue($policy->canBlockLeaderIndex($blockLeader, $resource));
        $this->assertTrue($policy->canBlockLeaderApprove($blockLeader, $resource));
        $this->assertTrue($policy->canBlockLeaderReject($blockLeader, $resource));

        $this->assertFalse($policy->canBlockLeaderIndex($staff, $resource));
        $this->assertFalse($policy->canBlockLeaderApprove($staff, $resource));
        $this->assertFalse($policy->canBlockLeaderReject($staff, $resource));
        $this->assertFalse($policy->canBlockLeaderIndex(null, $resource));
    }

    public function testAdminActionsAllowOnlyAdmin(): void
    {
        $policy = new ApprovalPolicy();
        $resource = null;

        $admin = new ApprovalTestIdentity(['i_id_user' => 1, 'i_admin' => 1]);
        $blockLeader = new ApprovalTestIdentity(['i_id_user' => 2, 'i_admin' => 2]);
        $staff = new ApprovalTestIdentity(['i_id_user' => 3, 'i_admin' => 0]);

        $this->assertTrue($policy->canAdminIndex($admin, $resource));
        $this->assertTrue($policy->canAdminApprove($admin, $resource));
        $this->assertTrue($policy->canAdminReject($admin, $resource));
        $this->assertTrue($policy->canAdminReflect($admin, $resource));

        $this->assertFalse($policy->canAdminIndex($blockLeader, $resource));
        $this->assertFalse($policy->canAdminApprove($blockLeader, $resource));
        $this->assertFalse($policy->canAdminReject($blockLeader, $resource));
        $this->assertFalse($policy->canAdminReflect($blockLeader, $resource));

        $this->assertFalse($policy->canAdminIndex($staff, $resource));
        $this->assertFalse($policy->canAdminApprove($staff, $resource));
        $this->assertFalse($policy->canAdminReject($staff, $resource));
        $this->assertFalse($policy->canAdminReflect($staff, $resource));
        $this->assertFalse($policy->canAdminIndex(null, $resource));
    }
}

class ApprovalTestIdentity implements IdentityInterface
{
    /**
     * @param array<string, mixed> $data
     */
    public function __construct(private array $data)
    {
    }

    public function can(string $action, mixed $resource): bool
    {
        return false;
    }

    public function canResult(string $action, mixed $resource): ResultInterface
    {
        throw new \BadMethodCallException('Not used in policy tests.');
    }

    public function applyScope(string $action, mixed $resource, mixed ...$optionalArgs): mixed
    {
        return $resource;
    }

    public function getOriginalData(): \ArrayAccess|array
    {
        return $this->data;
    }

    public function offsetExists(mixed $offset): bool
    {
        return isset($this->data[$offset]);
    }

    public function offsetGet(mixed $offset): mixed
    {
        return $this->data[$offset] ?? null;
    }

    public function offsetSet(mixed $offset, mixed $value): void
    {
        $this->data[$offset] = $value;
    }

    public function offsetUnset(mixed $offset): void
    {
        unset($this->data[$offset]);
    }
}
