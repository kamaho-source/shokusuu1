<?php
declare(strict_types=1);

namespace App\Test\TestCase\Policy;

use App\Controller\RoomUsageController;
use App\Policy\RoomUsagePolicy;
use Cake\ORM\Entity;
use Cake\TestSuite\TestCase;

/**
 * RoomUsagePolicy: Identity の元データが Entity / ArrayObject / 想定外の場合の判定。
 */
class RoomUsagePolicyIdentityShapeTest extends TestCase
{
    /** @return array<string, array{0:int, 1:bool}> */
    public static function levels(): array
    {
        return ['一般' => [0, false], '管理者' => [1, false], 'システム管理者' => [3, true]];
    }

    /** @dataProvider levels */
    public function testEntityAndArrayAccessIdentity(int $level, bool $expected): void
    {
        $policy = new RoomUsagePolicy();
        $controller = $this->createMock(RoomUsageController::class);

        foreach ([new Entity(['i_admin' => $level]), new \ArrayObject(['i_admin' => $level])] as $original) {
            $user = new ObjectPolicyIdentity($original);
            $this->assertSame($expected, $policy->canIndex($user, $controller));
            $this->assertSame($expected, $policy->canRoomUsage($user, $controller));
            $this->assertSame($expected, $policy->canLowUsageRooms($user, $controller));
        }
    }
}
