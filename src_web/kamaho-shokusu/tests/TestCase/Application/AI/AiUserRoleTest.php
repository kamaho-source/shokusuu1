<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\AI;

use App\Application\AI\AiUserRole;
use Cake\TestSuite\TestCase;

class AiUserRoleTest extends TestCase
{
    public function testConstants_areDistinctIdentifiers(): void
    {
        $roles = [AiUserRole::CHILD, AiUserRole::GENERAL, AiUserRole::BLOCK_LEADER, AiUserRole::ADMIN];

        $this->assertSame($roles, array_values(array_unique($roles)));
        $this->assertSame('child', AiUserRole::CHILD);
        $this->assertSame('admin', AiUserRole::ADMIN);
    }

    public function testConstructor_isPrivate(): void
    {
        $ctor = (new \ReflectionClass(AiUserRole::class))->getConstructor();

        $this->assertNotNull($ctor);
        $this->assertTrue($ctor->isPrivate());
    }
}
