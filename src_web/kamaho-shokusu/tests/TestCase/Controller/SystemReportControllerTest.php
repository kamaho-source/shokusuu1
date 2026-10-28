<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Datasource\ConnectionManager;
use Cake\I18n\Date;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * SystemReportController 統合テスト
 *
 * 権限（管理者のみ）、日付パラメータの検証、集計失敗時の 500 を確認する。
 */
class SystemReportControllerTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TIndividualReservationInfo',
        'app.TAuditLog',
    ];

    private function loginAs(int $adminLevel): void
    {
        [$userId, $account] = match ($adminLevel) {
            3 => [4, 'system_admin_user'],
            1 => [1, 'admin_user'],
            default => [2, 'staff_user'],
        };
        $this->session([
            'Auth' => [
                'i_id_user' => $userId,
                'c_login_account' => $account,
                'c_user_name' => 'テスト',
                'i_admin' => $adminLevel,
                'i_user_level' => 0,
                'i_id_room' => 1,
            ],
        ]);
    }

    /**
     * @return array<string, array{0:string}>
     */
    public static function pageActions(): array
    {
        return [
            'index' => ['/SystemReport'],
            'dailyChildren' => ['/SystemReport/dailyChildren'],
            'loginReport' => ['/SystemReport/loginReport'],
        ];
    }

    /**
     * @dataProvider pageActions
     */
    public function testPage_admin_returns200(string $url): void
    {
        $this->loginAs(1);
        $this->get($url);
        $this->assertResponseOk();
    }

    /**
     * @dataProvider pageActions
     */
    public function testPage_general_redirectsWithFlash(string $url): void
    {
        $this->loginAs(0);
        $this->get($url);
        $this->assertHeader('Location', 'http://localhost/');
        $this->assertFlashMessage('この機能を利用する権限がありません。', 'flash');
    }

    /**
     * @return array<string, array{0:string}>
     */
    public static function dataActions(): array
    {
        return [
            'data' => ['/SystemReport/data'],
            'dailyChildrenData' => ['/SystemReport/dailyChildrenData'],
            'loginReportData' => ['/SystemReport/loginReportData'],
        ];
    }

    /**
     * @dataProvider dataActions
     */
    public function testData_general_returns403Json(string $url): void
    {
        $this->loginAs(0);
        $this->get($url);
        $this->assertResponseCode(403);
        $this->assertSame(false, json_decode((string)$this->_response->getBody(), true)['success']);
    }

    /**
     * @dataProvider dataActions
     */
    public function testData_invalidDate_returns422(string $url): void
    {
        $this->loginAs(1);
        $this->get($url . '?date_from=2026-13-40&date_to=2026-01-01');
        $this->assertResponseCode(422);
        $this->assertStringContainsString('YYYY-MM-DD', (string)$this->_response->getBody());
    }

    /**
     * @dataProvider dataActions
     */
    public function testData_fromAfterTo_returns422(string $url): void
    {
        $this->loginAs(1);
        $this->get($url . '?date_from=2026-02-02&date_to=2026-02-01');
        $this->assertResponseCode(422);
        $this->assertStringContainsString('開始日は終了日以前', (string)$this->_response->getBody());
    }

    /**
     * @dataProvider dataActions
     */
    public function testData_rangeOver366Days_returns422(string $url): void
    {
        $this->loginAs(1);
        $this->get($url . '?date_from=2025-01-01&date_to=2026-01-02');
        $this->assertResponseCode(422);
        $this->assertStringContainsString('最大 366 日', (string)$this->_response->getBody());
    }

    public function testData_valid_returnsRoomStatsAndStoresExcludeInSession(): void
    {
        $this->loginAs(1);
        $day = Date::today()->subDays(2)->format('Y-m-d');
        $this->get("/SystemReport/data?date_from={$day}&date_to={$day}&exclude[]=1&exclude[]=0&exclude[]=abc");
        $this->assertResponseOk();
        $json = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame($day, $json['date_from']);
        $this->assertSame($day, $json['date_to']);
        $this->assertCount(1, $json['room_stats']);
        // 除外 ID（正の整数のみ）が保存される。0 と abc は捨てられる
        $this->assertSame([1], $_SESSION['SystemReport']['excludeUserIds'] ?? null);
        // user 1 を除外した結果、大人は user 2 のみ（子供は user 3）
        $this->assertSame(1, $json['room_stats'][0]['adult_users']);
        $this->assertSame(1, $json['room_stats'][0]['child_users']);
    }

    public function testData_scalarExclude_isAccepted(): void
    {
        $this->loginAs(1);
        $this->get('/SystemReport/data?exclude=2');
        $this->assertResponseOk();
        $this->assertSame([2], $_SESSION['SystemReport']['excludeUserIds'] ?? null);
    }

    public function testDailyChildrenData_valid_returnsStats(): void
    {
        $this->loginAs(1);
        $this->get('/SystemReport/dailyChildrenData?date_from=2026-03-01&date_to=2026-03-03&exclude[]=3');
        $this->assertResponseOk();
        $json = json_decode((string)$this->_response->getBody(), true);
        $this->assertCount(3, $json['stats']);
        $this->assertSame(['date', 'child_count'], array_keys($json['stats'][0]));
        $this->assertSame([3], $_SESSION['SystemReport']['excludeChildIds'] ?? null);
    }

    public function testDailyChildren_page_usesSavedExclusion(): void
    {
        $this->loginAs(1);
        $this->session(['SystemReport' => ['excludeChildIds' => [3]]]);
        $this->get('/SystemReport/dailyChildren');
        $this->assertResponseOk();
        $this->assertSame([3], $this->viewVariable('excludeUserIds'));
    }

    public function testLoginReportData_valid_returnsDailyAndLogs(): void
    {
        $this->loginAs(1);
        $this->get('/SystemReport/loginReportData?date_from=2000-01-01&date_to=2000-01-03');
        $this->assertResponseOk();
        $json = json_decode((string)$this->_response->getBody(), true);
        $this->assertCount(3, $json['daily']);
        $this->assertArrayHasKey('logs', $json);
        $this->assertSame('2000-01-01', $json['date_from']);
    }

    /**
     * @return array<string, array{0:string, 1:string}>
     */
    public static function failingActions(): array
    {
        return [
            'data' => ['/SystemReport/data', 'm_user_group'],
            'dailyChildrenData' => ['/SystemReport/dailyChildrenData', 't_individual_reservation_info'],
            'loginReportData' => ['/SystemReport/loginReportData', 't_audit_log'],
        ];
    }

    /**
     * 集計中に DB 例外が出ても 500 の定型文だけを返し、内部エラーを漏らさない。
     *
     * @dataProvider failingActions
     */
    public function testData_dbFailure_returns500WithoutLeakingMessage(string $url, string $table): void
    {
        $conn = ConnectionManager::get('test');
        $conn->execute("ALTER TABLE {$table} RENAME TO {$table}_bak");
        try {
            $this->loginAs(1);
            $this->get($url);
        } finally {
            $conn->execute("ALTER TABLE {$table}_bak RENAME TO {$table}");
        }
        $this->assertResponseCode(500);
        $body = (string)$this->_response->getBody();
        $this->assertStringContainsString('集計処理に失敗しました', $body);
        $this->assertStringNotContainsString($table, $body);
    }
}
