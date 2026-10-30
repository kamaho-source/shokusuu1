<?php
declare(strict_types=1);

namespace App\Test\TestCase\Policy;

use App\Controller\ReservationHistoryController;
use App\Policy\ReservationHistoryPolicy;
use Cake\TestSuite\TestCase;

/**
 * ReservationHistoryPolicy テスト
 *
 * 管理者・システム管理者・ブロック長・職員（i_user_level 0/7）だけが開ける。子ども・一般・未ログインは開けない。
 */
class ReservationHistoryPolicyTest extends TestCase
{
    private ReservationHistoryPolicy $policy;

    private ReservationHistoryController $resource;

    protected function setUp(): void
    {
        parent::setUp();
        $this->policy = new ReservationHistoryPolicy();
        $this->resource = $this->createMock(ReservationHistoryController::class);
    }

    /**
     * @return array<string, array{int, ?int, bool}>
     */
    public static function roleProvider(): array
    {
        return [
            '管理者' => [1, 1, true],
            'システム管理者' => [3, 1, true],
            '職員(level0)' => [0, 0, true],
            '職員(level7)' => [0, 7, true],
            '子ども' => [0, 1, false],
            'ブロック長' => [2, 1, true],
            'level未設定' => [0, null, false],
        ];
    }

    /**
     * @dataProvider roleProvider
     */
    public function testCanIndex(int $admin, ?int $level, bool $expected): void
    {
        $user = new PolicyTestIdentity(['i_admin' => $admin, 'i_user_level' => $level]);
        $this->assertSame($expected, $this->policy->canIndex($user, $this->resource));
    }

    public function testCanIndex_guest_denied(): void
    {
        $this->assertFalse($this->policy->canIndex(null, $this->resource));
    }
}
