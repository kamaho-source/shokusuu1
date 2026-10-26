<?php
declare(strict_types=1);

namespace App\Test\TestCase\Policy;

use App\Policy\StatsAiPolicy;
use Cake\TestSuite\TestCase;

/**
 * StatsAiPolicy テスト。システム管理者（i_admin=3）のみ許可。
 */
class StatsAiPolicyTest extends TestCase
{
    /** @return array<string, array{0:?int, 1:bool}> */
    public static function levels(): array
    {
        return ['一般' => [0, false], '管理者' => [1, false], 'システム管理者' => [3, true], '未設定' => [null, false]];
    }

    /** @dataProvider levels */
    public function testCanIndexAndAskStream(?int $level, bool $expected): void
    {
        $policy = new StatsAiPolicy();
        $user = new ObjectPolicyIdentity([], $level);

        $this->assertSame($expected, $policy->canIndex($user, null));
        $this->assertSame($expected, $policy->canAskStream($user, null));
    }
}
