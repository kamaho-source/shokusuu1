<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Controller\TReservationInfoController;
use Cake\Datasource\ConnectionManager;
use Cake\Http\ServerRequest;
use Cake\I18n\Date;
use Cake\I18n\DateTime;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

class TReservationInfoControllerTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.TReservationInfo',
        'app.MRoomInfo',
        'app.MUserInfo',
        'app.MUserGroup',
        'app.TIndividualReservationInfo',
    ];

    /**
     * @var \App\Controller\TReservationInfoController
     */
    protected $Controller;

    public function setUp(): void
    {
        parent::setUp();
        $this->Controller = new TReservationInfoController(new ServerRequest());
        $this->Controller->initialize();
    }

    public function tearDown(): void
    {
        unset($this->Controller);
        parent::tearDown();
    }

    /**
     * validateReservationDateのテスト
     */
    public function testValidateReservationDate()
    {
        // 予約日未入力
        $result = $this->invokeMethod($this->Controller, 'validateReservationDate', ['']);
        $this->assertSame('予約日が指定されていません。', $result);

        // 無効な日付フォーマット
        $result = $this->invokeMethod($this->Controller, 'validateReservationDate', ['not-a-date']);
        $this->assertSame('無効な日付フォーマットです。', $result);

        // 今日 < 15日後 の日付（15日未満なのでエラー）
        $today = Date::today();
        $in2weeks = $today->addDays(14)->format('Y-m-d');
        $result = $this->invokeMethod($this->Controller, 'validateReservationDate', [$in2weeks]);
        $minDate = $today->addDays(15)->format('Y-m-d');
        $this->assertSame(
            sprintf('通常発注は「きょうから15日目以降」のみ登録できます（%s 以降）。', $minDate),
            $result
        );

        // ちょうど1ヶ月後（予約可能）
        $oneMonthLater = $today->addMonths(1)->format('Y-m-d');
        $result = $this->invokeMethod($this->Controller, 'validateReservationDate', [$oneMonthLater]);
        $this->assertTrue($result);

        // 1ヶ月より後（予約可能）
        $overMonth = $today->addMonths(1)->addDays(1)->format('Y-m-d');
        $result = $this->invokeMethod($this->Controller, 'validateReservationDate', [$overMonth]);
        $this->assertTrue($result);
    }

    /* コントローラのprivate/protected メソッドを呼び出すためのヘルパー */
    private function invokeMethod(&$object, $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        return $method->invokeArgs($object, $parameters);
    }

    /**
     * indexアクションのテスト
     */
    public function testIndex()
    {
        // ユーザー認証モック
        $this->setAuthenticatedSession();
        $this->get('/t-reservation-info/index');
        $this->assertResponseOk();
        $this->assertResponseContains('食数予約');
    }

    /**
     * viewアクションのテスト（正常系）
     */
    public function testViewSuccess()
    {
        $this->setAuthenticatedSession();
        $date = (new Date('+1 month'))->format('Y-m-d');
        $this->get('/t-reservation-info/view?date=' . $date);
        $this->assertResponseOk();
    }

    /**
     * viewアクションのテスト（日付パラメータなし）
     */
    public function testViewNoDate()
    {
        $this->setAuthenticatedSession();
        $this->get('/t-reservation-info/view');
        $this->assertResponseOk();
    }

    /**
     * eventsアクションのテスト
     */
    public function testEvents()
    {
        $this->setAuthenticatedSession();
        $this->get('/t-reservation-info/events');
        $this->assertResponseOk();
        $this->assertHeader('Content-Type', 'application/json');

        $responseJson = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame(true, $responseJson['ok'] ?? null);
        $this->assertIsArray($responseJson['data']['events'] ?? null);
    }

    public function testCalendarEventsInvalidRangeContract(): void
    {
        $this->setAuthenticatedSession();
        $this->get('/t-reservation-info/calendar-events?start=invalid&end=invalid');
        $this->assertResponseCode(400);

        $responseJson = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame(false, $responseJson['ok'] ?? null);
        $this->assertIsArray($responseJson['data'] ?? null);
    }

    /**
     * getUsersByRoomForBulkアクションのテスト
     */
    public function testGetUsersByRoomForBulk()
    {
        $this->setAuthenticatedSession();
        // 有効なルームIDでテスト
        $this->get('/t-reservation-info/getUsersByRoomForBulk/1');
        $this->assertResponseOk();
        $this->assertHeader('Content-Type', 'application/json');

        // レスポンスのJSONを解析
        $responseJson = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame(true, $responseJson['ok'] ?? null);
        $this->assertArrayHasKey('users', $responseJson['data'] ?? []);

        // 無効なルームIDでテスト（ユーザーが存在しない場合）
        $this->get('/t-reservation-info/getUsersByRoomForBulk/999');
        $this->assertResponseOk(); // エラーではなく空の結果を返すべき
        $responseJson = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame(true, $responseJson['ok'] ?? null);
        $this->assertArrayHasKey('users', $responseJson['data'] ?? []);
        $this->assertEmpty($responseJson['data']['users']);
    }

    public function testGetUsersByRoomUsesChangeFlagAtFourteenDays(): void
    {
        $this->setAuthenticatedSession();
        $this->insertActiveUserInRoom(11, 1);

        $date = Date::today()->addDays(14)->format('Y-m-d');
        $this->insertReservation(11, 1, $date, 1, 0, 1);

        $this->get('/t-reservation-info/get-users-by-room/1?date=' . $date);
        $this->assertResponseOk();

        $responseJson = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame(true, $responseJson['ok'] ?? null);
        $this->assertTrue($this->findUserMealFlag($responseJson, 11, 'morning'));
    }

    public function testGetUsersByRoomUsesEatFlagAtFifteenDays(): void
    {
        $this->setAuthenticatedSession();
        $this->insertActiveUserInRoom(12, 1);

        $date = Date::today()->addDays(15)->format('Y-m-d');
        $this->insertReservation(12, 1, $date, 1, 0, 1);

        $this->get('/t-reservation-info/get-users-by-room/1?date=' . $date);
        $this->assertResponseOk();

        $responseJson = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame(true, $responseJson['ok'] ?? null);
        $this->assertFalse($this->findUserMealFlag($responseJson, 12, 'morning'));
    }

    /**
     * bulkAddFormアクションのテスト（正常系）
     */
    public function testBulkAddFormSuccess()
    {
        // ユーザー認証モック
        $this->setAuthenticatedSession();

        $date = (new Date('+1 month'))->format('Y-m-d');
        $this->get('/t-reservation-info/bulk-add-form?date=' . $date);
        $this->assertResponseOk();
        $this->assertResponseContains('部屋を選択');
        $this->assertResponseContains('確定・保存');
    }

    /**
     * bulkAddFormアクションのテスト（日付なし）
     */
    public function testBulkAddFormNoDate()
    {
        // ユーザー認証モック
        $this->setAuthenticatedSession();

        $this->get('/t-reservation-info/bulk-add-form');
        $this->assertResponseSuccess(); // リダイレクトも成功レスポンス
        $this->assertRedirect(['action' => 'index']);
    }

    /**
     * bulkAddSubmitアクションのテスト（個人予約）
     */
    public function testBulkAddSubmitPersonal()
    {
        // ユーザー認証モック
        $this->setAuthenticatedSession();
        $this->enableCsrfToken();

        $date = (new Date('+2 months'))->format('Y-m-d');

        $data = [
            'reservation_type' => 'personal',
            'dates' => [
                $date => 1
            ],
            'meals' => [
                'morning' => [
                    '1' => 1 // 部屋ID 1の朝食
                ]
            ]
        ];

        $this->post('/t-reservation-info/bulk-add-submit', $data);
        $this->assertResponseOk();
        $this->assertHeader('Content-Type', 'application/json');

        // レスポンスのJSONを解析
        $responseJson = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('ok', $responseJson);
        $this->assertArrayHasKey('data', $responseJson);
    }

    /**
     * bulkAddSubmitアクションのテスト（集団予約）
     */
    public function testBulkAddSubmitGroup()
    {
        // ユーザー認証モック
        $this->setAuthenticatedSession();
        $this->enableCsrfToken();

        $date = (new Date('+2 months'))->format('Y-m-d');

        $data = [
            'reservation_type' => 'group',
            'dates' => [
                $date => 1
            ],
            'room_id' => 1,
            'users' => [
                '1' => [
                    'morning' => 1
                ]
            ]
        ];

        $this->post('/t-reservation-info/bulk-add-submit', $data);
        $this->assertResponseOk();
        $this->assertHeader('Content-Type', 'application/json');

        // レスポンスのJSONを解析
        $responseJson = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('ok', $responseJson);
        $this->assertArrayHasKey('data', $responseJson);
    }

    /**
     * bulkAddSubmitアクションのテスト（予約タイプなし）
     */
    public function testBulkAddSubmitNoType()
    {
        // ユーザー認証モック
        $this->setAuthenticatedSession();
        $this->enableCsrfToken();

        $data = []; // 予約タイプなし

        $this->post('/t-reservation-info/bulk-add-submit', $data);
        $this->assertResponseOk();
        $this->assertHeader('Content-Type', 'application/json');

        // レスポンスのJSONを解析
        $responseJson = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame(false, $responseJson['ok'] ?? null);
        $this->assertArrayHasKey('message', $responseJson);
        $this->assertArrayHasKey('data', $responseJson);
    }

    /**
     * bulkAddSubmitアクションのテスト（個人予約・日付なし）
     */
    public function testBulkAddSubmitPersonalNoDates()
    {
        // ユーザー認証モック
        $this->setAuthenticatedSession();
        $this->enableCsrfToken();

        $data = [
            'reservation_type' => 'personal',
            // 日付なし
            'meals' => [
                'morning' => [
                    '1' => 1
                ]
            ]
        ];

        $this->post('/t-reservation-info/bulk-add-submit', $data);
        $this->assertResponseOk();
        $this->assertHeader('Content-Type', 'application/json');

        // レスポンスのJSONを解析
        $responseJson = json_decode((string)$this->_response->getBody(), true);
        // 日付がないのでエラーになるはず
        $this->assertSame(false, $responseJson['ok'] ?? null);
    }

    /**
     * bulkAddSubmitアクションのテスト（個人予約・食事タイプなし）
     */
    public function testBulkAddSubmitPersonalNoMeals()
    {
        // ユーザー認証モック
        $this->setAuthenticatedSession();
        $this->enableCsrfToken();

        $date = (new Date('+2 months'))->format('Y-m-d');

        $data = [
            'reservation_type' => 'personal',
            'dates' => [
                $date => 1
            ]
            // 食事タイプなし
        ];

        $this->post('/t-reservation-info/bulk-add-submit', $data);
        $this->assertResponseOk();
        $this->assertHeader('Content-Type', 'application/json');

        // レスポンスのJSONを解析
        $responseJson = json_decode((string)$this->_response->getBody(), true);
        // 食事タイプがないのでエラーになるはず
        $this->assertSame(false, $responseJson['ok'] ?? null);
    }

    /**
     * bulkAddSubmitアクションのテスト（集団予約・部屋IDなし）
     */
    public function testBulkAddSubmitGroupNoRoom()
    {
        // ユーザー認証モック
        $this->setAuthenticatedSession();
        $this->enableCsrfToken();

        $date = (new Date('+2 months'))->format('Y-m-d');

        $data = [
            'reservation_type' => 'group',
            'dates' => [
                $date => 1
            ],
            // 部屋IDなし
            'users' => [
                '1' => [
                    'morning' => 1
                ]
            ]
        ];

        $this->post('/t-reservation-info/bulk-add-submit', $data);
        $this->assertResponseOk();
        $this->assertHeader('Content-Type', 'application/json');

        // レスポンスのJSONを解析
        $responseJson = json_decode((string)$this->_response->getBody(), true);
        // 部屋IDがないのでエラーになるはず
        $this->assertSame(false, $responseJson['ok'] ?? null);
    }

    public function testCopyAsNonAdminDenied(): void
    {
        $this->setAuthenticatedSession(false, 1);
        $this->enableCsrfToken();

        $this->post('/t-reservation-info/copy', [
            'mode' => 'week',
            'source' => (new Date('+2 months'))->format('Y-m-d'),
            'target' => (new Date('+3 months'))->format('Y-m-d'),
        ]);

        $this->assertResponseCode(403);
        $responseJson = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame(false, $responseJson['ok'] ?? null);
        $this->assertSame('権限がありません。', $responseJson['message'] ?? null);
    }

    public function testCopyAsAdminSuccess(): void
    {
        $this->setAuthenticatedSession(true, 0);
        $this->enableCsrfToken();

        $this->post('/t-reservation-info/copy', [
            'mode' => 'week',
            'source' => (new Date('+2 months'))->format('Y-m-d'),
            'target' => (new Date('+3 months'))->format('Y-m-d'),
            'room_id' => 1,
        ]);

        $this->assertResponseOk();
        $this->assertHeader('Content-Type', 'application/json');
        $responseJson = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame(true, $responseJson['ok'] ?? null);
        $this->assertArrayHasKey('data', $responseJson);
    }

    public function testBulkAddSubmitAsNonStaffDenied(): void
    {
        $this->setAuthenticatedSession(false, 1);
        $this->enableCsrfToken();

        $date = (new Date('+2 months'))->format('Y-m-d');
        $this->post('/t-reservation-info/bulk-add-submit', [
            'reservation_type' => 'personal',
            'dates' => [$date => 1],
            'meals' => ['morning' => ['1' => 1]],
        ]);

        $this->assertResponseCode(403);
    }

    public function testBulkAddSubmitAsStaffAllowed(): void
    {
        $this->setAuthenticatedSession(false, 0);
        $this->enableCsrfToken();

        $date = (new Date('+2 months'))->format('Y-m-d');
        $this->post('/t-reservation-info/bulk-add-submit', [
            'reservation_type' => 'personal',
            'dates' => [$date => 1],
            'meals' => ['morning' => ['1' => 1]],
        ]);

        $this->assertResponseOk();
        $this->assertHeader('Content-Type', 'application/json');
    }

    public function testGetAllRoomsMealCountsAsNonAdminDenied(): void
    {
        $this->setAuthenticatedSession(false, 0);
        $this->get('/t-reservation-info/get-all-rooms-meal-counts');
        $this->assertResponseCode(403);
    }

    public function testGetUsersByRoomAsStaffOtherRoomDenied(): void
    {
        $this->setAuthenticatedSession(false, 0);
        $this->get('/t-reservation-info/get-users-by-room/2');
        $this->assertResponseCode(403);
    }

    public function testExportJsonAsNonStaffDenied(): void
    {
        $this->setAuthenticatedSession(false, 1);
        $this->get('/t-reservation-info/export-json?from=2025-01-01&to=2025-01-02');
        $this->assertResponseCode(403);
    }

    public function testExportJsonrankAsStaffAllowed(): void
    {
        $this->setAuthenticatedSession(false, 0);
        $this->get('/t-reservation-info/export-jsonrank?month=2025-01');
        $this->assertNotSame(403, $this->_response->getStatusCode());
        if ($this->_response->getStatusCode() === 200) {
            $responseJson = json_decode((string)$this->_response->getBody(), true);
            $this->assertArrayHasKey('ok', $responseJson);
            $this->assertArrayHasKey('data', $responseJson);
        }
    }

    public function testExportJsonContractNoData(): void
    {
        $this->setAuthenticatedSession(false, 0);
        $this->get('/t-reservation-info/export-json?from=2099-01-01&to=2099-01-02');
        $this->assertResponseOk();

        $responseJson = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame(true, $responseJson['ok'] ?? null);
        $this->assertIsArray($responseJson['data']['overall'] ?? null);
        $this->assertIsArray($responseJson['data']['rooms'] ?? null);
    }

    public function testExportJsonInvalidRangeContract(): void
    {
        $this->setAuthenticatedSession(false, 0);
        $this->get('/t-reservation-info/export-json?from=2025-01-10&to=2025-01-01');
        $this->assertResponseCode(400);

        $responseJson = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame(false, $responseJson['ok'] ?? null);
        $this->assertIsArray($responseJson['data'] ?? null);
    }

    public function testExportJsonrankInvalidMonthContract(): void
    {
        $this->setAuthenticatedSession(false, 0);
        $this->get('/t-reservation-info/export-jsonrank?month=2025-13');
        $this->assertResponseCode(400);

        $responseJson = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame(false, $responseJson['ok'] ?? null);
        $this->assertIsArray($responseJson['data'] ?? null);
    }

    /**
     * @dataProvider authorizationGetMatrixProvider
     */
    public function testAuthorizationGetMatrix(string $url, bool $isAdmin, int $userLevel, int $expectedStatus): void
    {
        $this->setAuthenticatedSession($isAdmin, $userLevel);
        $this->get($url);
        $this->assertResponseCode($expectedStatus);
    }

    public static function authorizationGetMatrixProvider(): array
    {
        return [
            'index staff allowed' => ['/t-reservation-info/index', false, 0, 200],
            'events staff allowed' => ['/t-reservation-info/events', false, 0, 200],
            'copy endpoint denied by method as get' => ['/t-reservation-info/copy', true, 0, 405],
            'events non staff denied' => ['/t-reservation-info/events', false, 1, 403],
            'calendar events non staff denied' => ['/t-reservation-info/calendar-events?start=2025-01-01&end=2025-01-31', false, 1, 403],
            'all rooms non admin denied' => ['/t-reservation-info/get-all-rooms-meal-counts', false, 0, 403],
            'room users other room denied' => ['/t-reservation-info/get-users-by-room/2', false, 0, 403],
            'export non staff denied' => ['/t-reservation-info/export-json?from=2025-01-01&to=2025-01-02', false, 1, 403],
        ];
    }

    /**
     * @dataProvider authorizationPostMatrixProvider
     */
    public function testAuthorizationPostMatrix(string $url, array $data, bool $isAdmin, int $userLevel, int $expectedStatus): void
    {
        $this->setAuthenticatedSession($isAdmin, $userLevel);
        $this->enableCsrfToken();
        $this->post($url, $data);
        $this->assertResponseCode($expectedStatus);
    }

    public static function authorizationPostMatrixProvider(): array
    {
        $date = (new Date('+2 months'))->format('Y-m-d');

        return [
            'copy admin allowed' => [
                '/t-reservation-info/copy',
                ['mode' => 'week', 'source' => $date, 'target' => (new Date('+3 months'))->format('Y-m-d'), 'room_id' => 1],
                true,
                0,
                200,
            ],
            'copy non admin denied' => [
                '/t-reservation-info/copy',
                ['mode' => 'week', 'source' => $date, 'target' => (new Date('+3 months'))->format('Y-m-d'), 'room_id' => 1],
                false,
                1,
                403,
            ],
            'bulk add staff allowed' => [
                '/t-reservation-info/bulk-add-submit',
                ['reservation_type' => 'personal', 'dates' => [$date => 1], 'meals' => ['morning' => ['1' => 1]]],
                false,
                0,
                200,
            ],
            'bulk add non staff denied' => [
                '/t-reservation-info/bulk-add-submit',
                ['reservation_type' => 'personal', 'dates' => [$date => 1], 'meals' => ['morning' => ['1' => 1]]],
                false,
                1,
                403,
            ],
            'check duplicate other room denied' => [
                '/t-reservation-info/check-duplicate-reservation',
                ['d_reservation_date' => $date, 'i_id_room' => 2, 'reservation_type' => 1],
                false,
                0,
                403,
            ],
            'reservation snapshots other room denied' => [
                '/t-reservation-info/get-reservation-snapshots',
                ['room_id' => 2, 'dates' => [$date]],
                false,
                0,
                403,
            ],
        ];
    }

    /**
     * formatDuplicateMessageメソッドのテスト
     */
    public function testFormatDuplicateMessage()
    {
        $duplicates = [
            [
                'user_name' => 'ユーザー1',
                'meal_type' => '朝',
                'room_name' => '第1室',
            ],
            [
                'user_name' => 'ユーザー2',
                'meal_type' => '昼',
                'room_name' => '第2室',
            ],
        ];

        $result = $this->invokeMethod($this->Controller, 'formatDuplicateMessage', [$duplicates]);

        $this->assertStringContainsString('朝', $result);
        $this->assertStringContainsString('昼', $result);
        $this->assertStringContainsString('ユーザー1', $result);
        $this->assertStringContainsString('ユーザー2', $result);
        $this->assertStringContainsString('第1室', $result);
        $this->assertStringContainsString('第2室', $result);
    }

    private function setAuthenticatedSession(bool $isAdmin = true, int $userLevel = 0): void
    {
        $this->session([
            'Auth' => [
                'i_id_user' => 1,
                'c_user_name' => 'テストユーザー',
                'i_admin' => $isAdmin ? 1 : 0,
                'i_user_level' => $userLevel,
                'i_id_room' => 1,
            ],
        ]);
    }

    private function insertActiveUserInRoom(int $userId, int $roomId): void
    {
        $connection = ConnectionManager::get('test');
        $now = DateTime::now('Asia/Tokyo')->format('Y-m-d H:i:s');

        $connection->insert('m_user_info', [
            'i_id_user' => $userId,
            'c_login_account' => 'user' . $userId,
            'c_login_passwd' => 'dummy',
            'c_user_name' => 'User ' . $userId,
            'i_user_level' => 0,
            'i_admin' => 0,
            'i_del_flag' => 0,
            'dt_create' => $now,
            'dt_update' => $now,
        ]);

        $connection->insert('m_user_group', [
            'i_id_user' => $userId,
            'i_id_room' => $roomId,
            'active_flag' => 0,
            'dt_create' => $now,
            'dt_update' => $now,
        ]);
    }

    private function insertReservation(
        int $userId,
        int $roomId,
        string $date,
        int $mealType,
        int $eatFlag,
        int $changeFlag
    ): void {
        $connection = ConnectionManager::get('test');
        $now = DateTime::now('Asia/Tokyo')->format('Y-m-d H:i:s');

        $connection->insert('t_individual_reservation_info', [
            'i_id_user' => $userId,
            'd_reservation_date' => $date,
            'i_reservation_type' => $mealType,
            'i_id_room' => $roomId,
            'eat_flag' => $eatFlag,
            'i_change_flag' => $changeFlag,
            'i_version' => 1,
            'dt_create' => $now,
            'c_create_user' => 'test',
            'dt_update' => $now,
            'c_update_user' => 'test',
        ]);
    }

    private function findUserMealFlag(array $responseJson, int $userId, string $mealKey): bool
    {
        $users = $responseJson['data']['usersByRoom'] ?? [];
        foreach ($users as $user) {
            if ((int)($user['id'] ?? 0) === $userId) {
                return (bool)($user[$mealKey] ?? false);
            }
        }

        return false;
    }
}
