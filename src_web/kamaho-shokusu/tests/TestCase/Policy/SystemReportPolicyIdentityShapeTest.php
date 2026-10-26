<?php
declare(strict_types=1);

namespace App\Test\TestCase\Policy;

use App\Policy\SystemReportPolicy;
use Cake\ORM\Entity;
use Cake\TestSuite\TestCase;

/**
 * SystemReportPolicy: 全アクションと、Identity 元データの各形（Entity / ArrayObject / null ユーザー）。
 */
class SystemReportPolicyIdentityShapeTest extends TestCase
{
    /** @return array<string, array{0:array<string,int>, 1:bool}> */
    public static function users(): array
    {
        return [
            '一般' => [['i_admin' => 0, 'i_report_access' => 0], false],
            '管理者' => [['i_admin' => 1, 'i_report_access' => 0], true],
            'システム管理者' => [['i_admin' => 3, 'i_report_access' => 0], true],
            '閲覧権限のみ' => [['i_admin' => 0, 'i_report_access' => 1], true],
            '閲覧権限=2は不可' => [['i_admin' => 0, 'i_report_access' => 2], false],
        ];
    }

    /**
     * @param array<string,int> $data
     * @dataProvider users
     */
    public function testAllActions_forEveryIdentityShape(array $data, bool $expected): void
    {
        $policy = new SystemReportPolicy();
        $resource = new \stdClass();

        foreach ([new PolicyTestIdentity($data), new ObjectPolicyIdentity(new Entity($data)), new ObjectPolicyIdentity(new \ArrayObject($data))] as $user) {
            $this->assertSame($expected, $policy->canIndex($user, $resource));
            $this->assertSame($expected, $policy->canData($user, $resource));
            $this->assertSame($expected, $policy->canDailyChildren($user, $resource));
            $this->assertSame($expected, $policy->canDailyChildrenData($user, $resource));
            $this->assertSame($expected, $policy->canLoginReport($user, $resource));
            $this->assertSame($expected, $policy->canLoginReportData($user, $resource));
        }
    }

    public function testNullUser_denied(): void
    {
        $policy = new SystemReportPolicy();
        $this->assertFalse($policy->canIndex(null, new \stdClass()));
        $this->assertFalse($policy->canLoginReportData(null, new \stdClass()));
    }
}
