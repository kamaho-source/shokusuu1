<?php
declare(strict_types=1);

namespace App\Test\TestCase\Policy;

use App\Policy\SystemReportPolicy;
use Cake\TestSuite\TestCase;

/**
 * SystemReportPolicy テスト
 *
 * 管理者（i_admin 1/3）とレポート閲覧権限（i_report_access === 1）のユーザーのみ許可されることを検証する。
 */
class SystemReportPolicyTest extends TestCase
{
    /**
     * @dataProvider userProvider
     * @param array<string, int> $data
     */
    public function testCanIndex(array $data, bool $expected): void
    {
        $policy = new SystemReportPolicy();
        $this->assertSame($expected, $policy->canIndex(new PolicyTestIdentity($data), new \stdClass()));
    }

    /**
     * @return array<string, array{array<string, int>, bool}>
     */
    public static function userProvider(): array
    {
        return [
            '管理者' => [['i_admin' => 1, 'i_report_access' => 0], true],
            'システム管理者' => [['i_admin' => 3, 'i_report_access' => 0], true],
            '一般・権限あり' => [['i_admin' => 0, 'i_report_access' => 1], true],
            '一般・権限なし' => [['i_admin' => 0, 'i_report_access' => 0], false],
            'i_admin=2・権限なし' => [['i_admin' => 2, 'i_report_access' => 0], false],
        ];
    }

    public function testCanIndex_nullUser_denied(): void
    {
        $policy = new SystemReportPolicy();
        $this->assertFalse($policy->canIndex(null, new \stdClass()));
    }
}
