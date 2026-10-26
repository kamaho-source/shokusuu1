<?php
declare(strict_types=1);

namespace App\Test\TestCase\Policy;

use App\Policy\FeatureUsageSummaryPolicy;
use Cake\ORM\Entity;
use Cake\TestSuite\TestCase;

/**
 * FeatureUsageSummaryPolicy テスト。システム管理者（i_admin=3）のみ許可。
 */
class FeatureUsageSummaryPolicyTest extends TestCase
{
    /** @return array<string, array{0:int, 1:bool}> */
    public static function levels(): array
    {
        return ['一般' => [0, false], '管理者' => [1, false], 'ブロック長' => [2, false], 'システム管理者' => [3, true]];
    }

    /** @dataProvider levels */
    public function testCanIndex_arrayIdentity(int $level, bool $expected): void
    {
        $this->assertSame($expected, (new FeatureUsageSummaryPolicy())->canIndex(new PolicyTestIdentity(['i_admin' => $level]), null));
    }

    /** @dataProvider levels */
    public function testCanIndex_entityIdentity(int $level, bool $expected): void
    {
        $user = new ObjectPolicyIdentity(new Entity(['i_admin' => $level]));
        $this->assertSame($expected, (new FeatureUsageSummaryPolicy())->canIndex($user, null));
    }

    /** @dataProvider levels */
    public function testCanIndex_arrayAccessIdentity(int $level, bool $expected): void
    {
        $user = new ObjectPolicyIdentity(new \ArrayObject(['i_admin' => $level]));
        $this->assertSame($expected, (new FeatureUsageSummaryPolicy())->canIndex($user, null));
    }

    public function testCanIndex_nullAndMissingField_denied(): void
    {
        $policy = new FeatureUsageSummaryPolicy();
        $this->assertFalse($policy->canIndex(null, null));
        $this->assertFalse($policy->canIndex(new PolicyTestIdentity([]), null));
        $this->assertFalse($policy->canIndex(new ObjectPolicyIdentity(new \ArrayObject([])), null));
    }
}
