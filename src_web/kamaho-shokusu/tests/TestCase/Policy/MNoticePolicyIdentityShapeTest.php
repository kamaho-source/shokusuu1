<?php
declare(strict_types=1);

namespace App\Test\TestCase\Policy;

use App\Infrastructure\Persistence\Entity\MNotice;
use App\Policy\MNoticePolicy;
use Cake\ORM\Entity;
use Cake\TestSuite\TestCase;

/**
 * MNoticePolicy: Identity の元データが Entity / ArrayObject / 想定外の場合の判定。
 */
class MNoticePolicyIdentityShapeTest extends TestCase
{
    /** @return array<string, array{0:int, 1:bool}> */
    public static function levels(): array
    {
        return ['一般' => [0, false], '管理者' => [1, true], 'ブロック長' => [2, false], 'システム管理者' => [3, true]];
    }

    /** @dataProvider levels */
    public function testEntityIdentity(int $level, bool $expected): void
    {
        $policy = new MNoticePolicy();
        $notice = new MNotice();
        $user = new ObjectPolicyIdentity(new Entity(['i_admin' => $level]));

        $this->assertSame($expected, $policy->canIndex($user, $notice));
        $this->assertSame($expected, $policy->canEdit($user, $notice));
        $this->assertSame($expected, $policy->canDelete($user, $notice));
    }

    /** @dataProvider levels */
    public function testArrayAccessIdentity(int $level, bool $expected): void
    {
        $user = new ObjectPolicyIdentity(new \ArrayObject(['i_admin' => $level]));
        $this->assertSame($expected, (new MNoticePolicy())->canEdit($user, new MNotice()));
    }
}
