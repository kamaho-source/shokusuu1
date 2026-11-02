<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * AuditLogController 統合テスト
 *
 * - システム管理者（i_admin=3）のみ index / export にアクセスできる
 * - 一般管理者（i_admin=1）はリダイレクトされる
 * - export はCSVレスポンスを返す
 */
class AuditLogControllerTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.TAuditLog',
        'app.MUserInfo',
    ];

    // ----------------------------------------------------------------
    // index
    // ----------------------------------------------------------------

    public function testIndex_systemAdmin_returns200(): void
    {
        $this->setSession(3);
        $this->get('/AuditLog');
        $this->assertResponseOk();
    }

    public function testIndex_admin_redirected(): void
    {
        $this->setSession(1);
        $this->get('/AuditLog');
        $this->assertResponseSuccess();
        $this->assertRedirect();
    }

    public function testIndex_general_redirected(): void
    {
        $this->setSession(0);
        $this->get('/AuditLog');
        $this->assertResponseSuccess();
        $this->assertRedirect();
    }

    public function testIndex_blockLeader_redirected(): void
    {
        $this->setSession(2);
        $this->get('/AuditLog');
        $this->assertResponseSuccess();
        $this->assertRedirect();
    }

    // ----------------------------------------------------------------
    // index — フィルター
    // ----------------------------------------------------------------

    public function testIndex_categoryFilter_returns200(): void
    {
        $this->setSession(3);
        $this->get('/AuditLog?category=user');
        $this->assertResponseOk();
    }

    public function testIndex_actionFilter_returns200(): void
    {
        $this->setSession(3);
        $this->get('/AuditLog?action=login');
        $this->assertResponseOk();
    }

    public function testIndex_dateRangeFilter_returns200(): void
    {
        $this->setSession(3);
        $this->get('/AuditLog?date_from=2026-01-01&date_to=2026-12-31');
        $this->assertResponseOk();
    }

    public function testIndex_resultFilter_returns200(): void
    {
        $this->setSession(3);
        $this->get('/AuditLog?result=1');
        $this->assertResponseOk();
    }

    public function testIndex_actorFilter_returns200(): void
    {
        $this->setSession(3);
        $this->get('/AuditLog?actor=admin');
        $this->assertResponseOk();
    }

    public function testIndex_targetIdFilter_returns200(): void
    {
        $this->setSession(3);
        $this->get('/AuditLog?target_id=1');
        $this->assertResponseOk();
    }

    // ----------------------------------------------------------------
    // export
    // ----------------------------------------------------------------

    public function testExport_systemAdmin_returnsCsv(): void
    {
        $this->setSession(3);
        $this->get('/AuditLog/export');
        $this->assertResponseOk();
        $this->assertContentType('text/csv');
    }

    public function testExport_systemAdmin_csvHasBom(): void
    {
        $this->setSession(3);
        $this->get('/AuditLog/export');
        $body = (string)$this->_response->getBody();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $body, 'CSV should start with UTF-8 BOM');
    }

    public function testExport_systemAdmin_csvHasHeader(): void
    {
        $this->setSession(3);
        $this->get('/AuditLog/export');
        $body = (string)$this->_response->getBody();
        $this->assertStringContainsString('カテゴリ', $body);
        $this->assertStringContainsString('操作種別', $body);
        $this->assertStringContainsString('操作日時', $body);
    }

    public function testExport_admin_redirected(): void
    {
        $this->setSession(1);
        $this->get('/AuditLog/export');
        $this->assertResponseSuccess();
        $this->assertRedirect();
    }

    // ----------------------------------------------------------------
    // export が実行されると audit_export ログが記録されること
    // ----------------------------------------------------------------

    public function testExport_recordsAuditLog(): void
    {
        $before = TableRegistry::getTableLocator()->get('TAuditLog')->find()->count();

        $this->setSession(3);
        $this->get('/AuditLog/export');

        $after = TableRegistry::getTableLocator()->get('TAuditLog')->find()->count();
        $this->assertSame($before + 1, $after);

        $log = TableRegistry::getTableLocator()->get('TAuditLog')
            ->find()
            ->orderBy(['i_id_audit' => 'DESC'])
            ->first();

        $this->assertSame('system',       $log->c_category);
        $this->assertSame('audit_export', $log->c_action);
    }

    // ----------------------------------------------------------------
    // 操作種別・カテゴリの日本語表示、ログイン失敗の操作者名
    // ----------------------------------------------------------------

    public function testIndex_showsJapaneseLabelsAndAccountNameForOldFailedLogin(): void
    {
        // 以前のログイン失敗: 操作者名にログインIDがそのまま入り、操作者IDは無い
        $this->addLog('user_login_failed', 'admin_user', 'admin_user', null, 0);
        $this->addLog('user_login_failed', 'no_such_account', 'no_such_account', null, 0);
        $this->setSession(3);
        $this->get('/AuditLog?action=user_login_failed');

        $this->assertResponseOk();
        $this->assertResponseContains('ログイン失敗');
        $this->assertResponseContains('ユーザー管理');
        $this->assertResponseContains('管理者ユーザー', 'ログインIDのアカウント名を出す');
        $this->assertResponseContains('（該当アカウントなし）');
    }

    public function testIndex_actionFilterIsExactForKnownAction(): void
    {
        $this->addLog('user_login', '管理者ユーザー', 'admin_user', 1, 1);
        $this->addLog('user_login_failed', '管理者ユーザー', 'admin_user', 1, 0);
        $this->setSession(3);
        $this->get('/AuditLog?action=user_login');

        $this->assertResponseOk();
        $this->assertResponseContains('<td title="user_login">ログイン</td>');
        $this->assertResponseNotContains('<td title="user_login_failed">', 'user_login で user_login_failed を拾わない');
    }

    public function testExport_csvHasJapaneseLabelsAndAccountName(): void
    {
        $this->addLog('user_login_failed', 'admin_user', 'admin_user', null, 0);
        $this->setSession(3);
        $this->get('/AuditLog/export?action=user_login_failed');

        $body = (string)$this->_response->getBody();
        $this->assertStringContainsString('操作種別コード', $body);
        $this->assertStringContainsString('ユーザー管理,ログイン失敗,user_login_failed', $body);
        $this->assertStringContainsString(',admin_user,管理者ユーザー,', $body);
    }

    /** ログインに失敗すると、操作者は入力されたログインIDのアカウント（無ければ「該当アカウントなし」）で記録される */
    public function testLoginFailure_recordsAccountAsActor(): void
    {
        $table = TableRegistry::getTableLocator()->get('TAuditLog');
        foreach ([['admin_user', '管理者ユーザー', 1], ['no_such_account', '（該当アカウントなし）', null]] as [$account, $name, $id]) {
            $this->enableCsrfToken();
            $this->enableSecurityToken();
            $this->post('/MUserInfo/login', ['c_login_account' => $account, 'c_login_passwd' => 'wrong-password']);

            $log = $table->find()->where(['c_action' => 'user_login_failed'])->orderBy(['i_id_audit' => 'DESC'])->first();
            $this->assertNotNull($log);
            $this->assertSame($account, $log->c_actor_login_id);
            $this->assertSame($name, $log->c_actor_user_name);
            $this->assertSame($id, $log->i_actor_user_id);
        }
    }

    // ----------------------------------------------------------------
    // 不正な日付・配列のクエリ
    // ----------------------------------------------------------------

    /**
     * 暦にない日付・配列の日付は 400（以前は 2/30 が翌月に繰り越して 200、配列は 500）
     *
     * @return array<string, array{string, string}>
     */
    public static function badDateQueryProvider(): array
    {
        $cases = [];
        foreach (['一覧' => '/AuditLog', 'CSV' => '/AuditLog/export'] as $name => $path) {
            $cases[$name . '・開始日が 2/30'] = [$path, 'date_from=2026-02-30'];
            $cases[$name . '・終了日が 13 月'] = [$path, 'date_to=2026-13-01'];
            $cases[$name . '・開始日が配列']  = [$path, 'date_from[]=x'];
        }

        return $cases;
    }

    /** @dataProvider badDateQueryProvider */
    public function testBadDateQuery_returns400(string $path, string $query): void
    {
        $this->setSession(3);
        $this->get($path . '?' . $query);

        $this->assertResponseCode(400);
        $this->assertResponseContains('不正なリクエストです');
    }

    /**
     * 日付以外の配列は絞り込みに使わず無視する（以前は警告や DB エラーで 500）
     *
     * @return array<string, array{string, string}>
     */
    public static function arrayFilterProvider(): array
    {
        $cases = [];
        foreach (['一覧' => '/AuditLog', 'CSV' => '/AuditLog/export'] as $name => $path) {
            foreach (['category', 'action', 'actor', 'target_id', 'result'] as $key) {
                $cases[$name . '・' . $key] = [$path, $key . '[]=x'];
            }
        }

        return $cases;
    }

    /** @dataProvider arrayFilterProvider */
    public function testArrayFilter_isIgnored(string $path, string $query): void
    {
        $this->setSession(3);
        $this->get($path . '?' . $query);

        $this->assertResponseOk();
        if ($path === '/AuditLog') {
            $this->assertCount(3, $this->viewVariable('logs'), '絞り込まずに fixture の 3 件が出る');

            return;
        }
        $rows = $this->csvRows();
        // fixture の 3 件と、この export 自身の記録 1 件
        $this->assertCount(4, $rows);
    }

    // ----------------------------------------------------------------
    // CSV の「結果」「失敗理由」列（i_result は boolean 型で true/false が返る）
    // ----------------------------------------------------------------

    public function testExport_resultColumnShowsSuccessAndFailure(): void
    {
        $this->setSession(3);
        $this->get('/AuditLog/export?category=user');

        $rows = $this->csvRows();
        $byAction = array_column($rows, null, '操作種別コード');
        $this->assertCount(2, $byAction);
        $this->assertSame('成功', $byAction['user_login']['結果'], '以前は成功の行まで「失敗」と出ていた');
        $this->assertSame('失敗', $byAction['user_login_failed']['結果']);
    }

    public function testExport_reasonColumnIsFilledOnlyForFailedRows(): void
    {
        $this->addLog('user_login', '管理者ユーザー', 'admin_user', 1, 1, '{"reason":"成功行の理由"}');
        $this->addLog('user_login_failed', 'admin_user', 'admin_user', null, 0, '{"reason":"パスワード誤り"}');
        $this->setSession(3);
        $this->get('/AuditLog/export?category=user&date_from=2026-10-01&date_to=2026-10-01');

        $rows = $this->csvRows();
        $byAction = array_column($rows, null, '操作種別コード');
        $this->assertCount(2, $byAction);
        $this->assertSame('成功', $byAction['user_login']['結果']);
        $this->assertSame('', $byAction['user_login']['失敗理由'], '成功の行には失敗理由を出さない');
        $this->assertSame('失敗', $byAction['user_login_failed']['結果']);
        $this->assertSame('パスワード誤り', $byAction['user_login_failed']['失敗理由']);
    }

    // ----------------------------------------------------------------
    // ヘルパー

    /**
     * CSV の本文を見出し付きの連想配列の行にする（BOM を除く）
     *
     * @return list<array<string, string>>
     */
    private function csvRows(): array
    {
        $body  = substr((string)$this->_response->getBody(), 3);
        $lines = array_map(
            static fn(string $line): array => str_getcsv($line, ',', '"', ''),
            array_filter(preg_split('/\r?\n/', $body), static fn(string $l): bool => $l !== '')
        );
        $header = array_shift($lines);

        return array_map(static fn(array $row): array => array_combine($header, $row), array_values($lines));
    }
    // ----------------------------------------------------------------

    private function addLog(string $action, string $actorName, string $loginId, ?int $actorId, int $result, ?string $detail = null): void
    {
        $table = TableRegistry::getTableLocator()->get('TAuditLog');
        $table->saveOrFail($table->newEntity([
            'c_category'        => 'user',
            'c_action'          => $action,
            'c_actor_user_name' => $actorName,
            'c_actor_login_id'  => $loginId,
            'i_actor_user_id'   => $actorId,
            'i_result'          => $result,
            'c_detail'          => $detail,
            'dt_create'         => '2026-10-01 10:00:00',
        ], ['accessibleFields' => ['*' => true]]));
    }

    private function setSession(int $adminLevel): void
    {
        [$userId, $loginAccount] = match($adminLevel) {
            3       => [4, 'system_admin_user'],
            1       => [1, 'admin_user'],
            default => [2, 'staff_user'],
        };

        $this->session([
            'Auth' => [
                'i_id_user'       => $userId,
                'c_login_account' => $loginAccount,
                'c_user_name'     => 'システム管理者',
                'i_admin'         => $adminLevel,
                'i_user_level'    => 0,
                'i_id_room'       => 1,
            ],
        ]);
    }
}
