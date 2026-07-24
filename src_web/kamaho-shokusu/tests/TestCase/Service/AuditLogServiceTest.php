<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\AuditLogService;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * AuditLogService テスト
 *
 * record() が t_audit_log に正しくレコードを保存することを確認する。
 * テーブルが壊れている場合に例外を握りつぶしてメイン処理を妨げないことも検証する。
 */
class AuditLogServiceTest extends TestCase
{
    protected array $fixtures = ['app.TAuditLog', 'app.MUserInfo'];

    private function table(): \Cake\ORM\Table
    {
        return TableRegistry::getTableLocator()->get('TAuditLog');
    }

    private function countAll(): int
    {
        return $this->table()->find()->count();
    }

    private function lastLog(): ?\Cake\Datasource\EntityInterface
    {
        return $this->table()->find()->orderBy(['i_id_audit' => 'DESC'])->first();
    }

    // ----------------------------------------------------------------
    // record() — 基本フィールド保存
    // ----------------------------------------------------------------

    public function testRecord_savesBasicFields(): void
    {
        $before = $this->countAll();

        AuditLogService::record(
            category:    'user',
            action:      'user_login',
            actorName:   'taro',
            actorId:     5,
            targetTable: 'm_user_info',
            targetId:    '5',
            detail:      ['key' => 'value'],
            ipAddress:   '192.168.0.1',
            result:      1
        );

        $this->assertSame($before + 1, $this->countAll());

        $log = $this->lastLog();
        $this->assertSame('user',           $log->c_category);
        $this->assertSame('user_login',     $log->c_action);
        $this->assertSame('taro',           $log->c_actor_user_name);
        $this->assertSame(5,                (int)$log->i_actor_user_id);
        $this->assertSame('m_user_info',    $log->c_target_table);
        $this->assertSame('5',              $log->c_target_id);
        $this->assertSame('192.168.0.1',    $log->c_ip_address);
        $this->assertSame(1,                (int)$log->i_result);
        $this->assertNotNull($log->c_detail);
        $this->assertStringContainsString('"key"', $log->c_detail);
        $this->assertStringContainsString('"value"', $log->c_detail);
    }

    // ----------------------------------------------------------------
    // actorId = 0 → i_actor_user_id は NULL
    // ----------------------------------------------------------------

    public function testRecord_actorIdZero_savesNull(): void
    {
        AuditLogService::record('user', 'user_login_failed', '不明', 0);

        $log = $this->lastLog();
        $this->assertNull($log->i_actor_user_id);
    }

    // ----------------------------------------------------------------
    // detail = null → c_detail は NULL
    // ----------------------------------------------------------------

    public function testRecord_nullDetail_savesNull(): void
    {
        AuditLogService::record('user', 'user_logout', 'taro', 1, null, null, null);

        $log = $this->lastLog();
        $this->assertNull($log->c_detail);
    }

    // ----------------------------------------------------------------
    // detail が JSON エンコードされること
    // ----------------------------------------------------------------

    public function testRecord_detailIsJsonEncoded(): void
    {
        AuditLogService::record('master', 'room_create', 'admin', 1, 'm_room_info', '99', ['room_name' => '特別室']);

        $log = $this->lastLog();
        $decoded = json_decode($log->c_detail, true);
        $this->assertIsArray($decoded);
        $this->assertSame('特別室', $decoded['room_name']);
    }

    // ----------------------------------------------------------------
    // i_result = 0 (失敗) が保存できること
    // ----------------------------------------------------------------

    public function testRecord_failureResult(): void
    {
        AuditLogService::record('user', 'user_create', 'admin', 1, 'm_user_info', null, null, null, 0);

        $log = $this->lastLog();
        $this->assertSame(0, (int)$log->i_result);
    }

    // ----------------------------------------------------------------
    // ipAddress = null → c_ip_address は NULL
    // ----------------------------------------------------------------

    public function testRecord_nullIpAddress_savesNull(): void
    {
        AuditLogService::record('actual_meal', 'actual_meal_save', 'user', 1, null, null, null, null);

        $log = $this->lastLog();
        $this->assertNull($log->c_ip_address);
    }

    // ----------------------------------------------------------------
    // targetTable・targetId が null でも保存できること
    // ----------------------------------------------------------------

    public function testRecord_nullTarget_savesNulls(): void
    {
        AuditLogService::record('system', 'audit_export', 'sysadmin', 3);

        $log = $this->lastLog();
        $this->assertNull($log->c_target_table);
        $this->assertNull($log->c_target_id);
    }

    // ----------------------------------------------------------------
    // DB書き込み失敗時は例外を外に出さないこと
    // ----------------------------------------------------------------

    public function testRecord_dbFailure_doesNotThrow(): void
    {
        // 存在しないテーブル名を使ったモックで TableRegistry を汚染せず、
        // テーブルを一時的に切り離す形で確認する。
        // ここでは「例外が外に出ないこと」を実証するため、別 locator を使う。
        $locator = new \Cake\ORM\Locator\TableLocator();
        $original = TableRegistry::getTableLocator();
        TableRegistry::setTableLocator($locator);

        // t_audit_log テーブルが存在しない locator では save が失敗するが、
        // record() は握りつぶすため例外が出ないはず
        try {
            // このブロックは例外を throw してはならない
            AuditLogService::record('user', 'user_login', 'taro', 1);
            $this->assertTrue(true, '例外が投げられなかった');
        } finally {
            TableRegistry::setTableLocator($original);
        }
    }

    // ----------------------------------------------------------------
    // dt_create が自動セットされること
    // ----------------------------------------------------------------

    public function testRecord_dtCreateIsSet(): void
    {
        $before = date('Y-m-d H:i:s');
        AuditLogService::record('user', 'user_logout', 'taro', 1);
        $after = date('Y-m-d H:i:s');

        $log = $this->lastLog();
        $dtCreate = $log->dt_create instanceof \DateTimeInterface
            ? $log->dt_create->format('Y-m-d H:i:s')
            : (string)$log->dt_create;
        $this->assertGreaterThanOrEqual($before, $dtCreate);
        $this->assertLessThanOrEqual($after,     $dtCreate);
    }

    // ----------------------------------------------------------------
    // 連続呼び出しで複数レコードが積まれること
    // ----------------------------------------------------------------

    public function testRecord_multipleCallsCreateMultipleRows(): void
    {
        $before = $this->countAll();

        AuditLogService::record('user', 'user_login',  'a', 1);
        AuditLogService::record('user', 'user_logout', 'a', 1);
        AuditLogService::record('master', 'room_create', 'b', 2);

        $this->assertSame($before + 3, $this->countAll());
    }

    // ----------------------------------------------------------------
    // カテゴリ一覧（全カテゴリで保存できること）
    // ----------------------------------------------------------------

    /**
     * @dataProvider categoryProvider
     */
    public function testRecord_allCategories(string $category): void
    {
        AuditLogService::record($category, 'some_action', 'taro', 1);

        $log = $this->lastLog();
        $this->assertSame($category, $log->c_category);
    }

    public static function categoryProvider(): array
    {
        return [
            ['user'],
            ['reservation'],
            ['actual_meal'],
            ['approval'],
            ['master'],
            ['system'],
        ];
    }
}
