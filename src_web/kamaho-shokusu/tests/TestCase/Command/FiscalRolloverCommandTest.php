<?php
declare(strict_types=1);

namespace App\Test\TestCase\Command;

use Cake\Console\TestSuite\ConsoleIntegrationTestTrait;
use Cake\ORM\Table;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * FiscalRolloverCommand テスト
 *
 * バグ1: 年齢遷移を降順で処理することにより、11歳ユーザーが11→12→13と
 *        同一実行で二重更新されず、11→12でちょうど1回だけ更新されることを検証する。
 * バグ2: 実行済みガード（t_audit_log 記録）により、同一年度の再実行が
 *        --force なしではブロックされ、--force 指定時のみ再実行できることを検証する。
 */
class FiscalRolloverCommandTest extends TestCase
{
    use ConsoleIntegrationTestTrait;

    protected array $fixtures = [
        'app.MUserInfo',
        'app.TAuditLog',
    ];

    private function userTable(): Table
    {
        return TableRegistry::getTableLocator()->get('MUserInfo');
    }

    private function createActiveUser(string $account, int $age): int
    {
        $table = $this->userTable();
        $user = $table->newEntity([
            'c_login_account' => $account,
            'c_login_passwd'  => 'password123',
            'c_user_name'     => $account . '_name',
            'i_user_age'      => $age,
            'i_user_rank'     => 0,
            'i_enable'        => 0, // 0 = 有効（本コマンドの対象）
            'i_del_flag'      => 0,
        ]);
        $table->saveOrFail($user);

        return (int)$user->i_id_user;
    }

    /**
     * バグ1の再発防止：11歳ユーザーは1回のコマンド実行で
     * i_user_age=12, i_user_rank=4 にちょうど1回だけ更新される（13歳・rank5にはならない）。
     */
    public function testElevenYearOldIsUpdatedExactlyOnce(): void
    {
        $userId = $this->createActiveUser('rollover_age11', 11);

        $this->exec('fiscal:rollover --date 2026-04-01');

        $this->assertExitSuccess();

        $user = $this->userTable()->get($userId);
        $this->assertSame(12, $user->i_user_age);
        $this->assertSame(4, $user->i_user_rank);
    }

    /**
     * バグ2の再発防止：4/1に一度実行した後、--force なしで再度実行しても
     * 実行済みガードで止まり、年齢は加算されない。
     */
    public function testSecondRunWithoutForceIsBlockedAfterAlreadyRun(): void
    {
        $userId = $this->createActiveUser('rollover_age11_guard', 11);

        $this->exec('fiscal:rollover --date 2026-04-01');
        $this->assertExitSuccess();

        $user = $this->userTable()->get($userId);
        $this->assertSame(12, $user->i_user_age);

        // 2回目（--force なし）は実行済みガードで止まる
        $this->exec('fiscal:rollover --date 2026-04-01');
        $this->assertExitSuccess();
        $this->assertOutputContains('実行済み');

        $user = $this->userTable()->get($userId);
        $this->assertSame(12, $user->i_user_age, '実行済みガードにより年齢は加算されないはず');
    }

    /**
     * --force 指定時は実行済みでも再実行できる。
     */
    public function testForceAllowsReRun(): void
    {
        $userId = $this->createActiveUser('rollover_age11_force', 11);

        $this->exec('fiscal:rollover --date 2026-04-01');
        $this->assertExitSuccess();

        $this->exec('fiscal:rollover --date 2026-04-01 --force');
        $this->assertExitSuccess();

        $user = $this->userTable()->get($userId);
        $this->assertSame(13, $user->i_user_age, '--force 指定時は再実行され年齢がさらに加算されるはず');
        $this->assertSame(5, $user->i_user_rank);
    }
}
