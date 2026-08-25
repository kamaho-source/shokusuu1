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
 * バグ1: 年齢+1とrank更新を単一のUPDATE文で行うことにより、境界年齢の
 *        1つ手前（5,8,10,14,17歳）のユーザーが二重加算されないことを検証する。
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
     * バグ1の再発防止（CodeRabbit指摘）：境界年齢の1つ手前（5,8,10,14,17歳）のユーザーは
     * 「一般加算で境界年齢になった直後に、境界更新にも一致してさらに+1される」
     * 二重加算が起きず、ちょうど+1されるだけで rank は変化しない。
     */
    public function testBoundaryMinusOneAgesAreNotDoubleIncremented(): void
    {
        $ages = [5, 8, 10, 14, 17];
        $userIds = [];
        foreach ($ages as $age) {
            $userIds[$age] = $this->createActiveUser('rollover_boundary_minus1_' . $age, $age);
        }

        $this->exec('fiscal:rollover --date 2026-04-01');
        $this->assertExitSuccess();

        foreach ($ages as $age) {
            $user = $this->userTable()->get($userIds[$age]);
            $this->assertSame($age + 1, $user->i_user_age, "{$age}歳は{$age}+1歳になるべき（二重加算されない）");
        }

        // 5歳 -> 6歳は「新しい年齢が3〜6のユーザはrank=1に統一」ルールの対象（別ロジック、意図通り）
        $this->assertSame(1, $this->userTable()->get($userIds[5])->i_user_rank);
        // 8,10,14,17歳はいずれも境界（6,9,11,12,15,18）ではないため rank は初期値のまま
        foreach ([8, 10, 14, 17] as $age) {
            $this->assertSame(0, $this->userTable()->get($userIds[$age])->i_user_rank, "{$age}歳のユーザーは境界年齢ではないため rank は変化しないはず");
        }
    }

    /**
     * バグ2関連の再発防止（CodeRabbit指摘）：AuditLogService::record() が実行済み
     * マーカーの保存に失敗した場合、年齢更新はロールバックされコミットされない
     * （マーカーが無いまま年齢だけ進むと、次回実行時に再度二重加算されてしまうため）。
     */
    public function testRollsBackWhenAuditMarkerFailsToPersist(): void
    {
        $userId = $this->createActiveUser('rollover_audit_fail', 11);

        $locator = TableRegistry::getTableLocator();
        $original = $locator->get('TAuditLog');
        $broken = new class ($original->getConnection()) extends Table {
            public function __construct($connection)
            {
                parent::__construct(['table' => 't_audit_log', 'alias' => 'TAuditLog', 'connection' => $connection]);
            }

            public function save(\Cake\Datasource\EntityInterface $entity, $options = []): \Cake\Datasource\EntityInterface|false
            {
                return false;
            }
        };
        $locator->set('TAuditLog', $broken);

        try {
            $this->exec('fiscal:rollover --date 2026-04-01');
            $this->assertExitError();
        } finally {
            $locator->set('TAuditLog', $original);
        }

        $user = $this->userTable()->get($userId);
        $this->assertSame(11, $user->i_user_age, 'マーカー保存失敗時は年齢更新もロールバックされるはず');
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
