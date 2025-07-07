<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Controller\TReservationInfoController;
use Cake\I18n\FrozenDate;
use Cake\I18n\FrozenTime;
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
        $this->Controller = new TReservationInfoController();
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

        // 今日 < 1ヶ月後 の日付（1ヶ月後未満なのでエラー）
        $current = FrozenTime::now();
        $in2weeks = (new FrozenTime($current))->modify('+2 weeks')->format('Y-m-d');
        $result = $this->invokeMethod($this->Controller, 'validateReservationDate', [$in2weeks]);
        $this->assertSame('当日から１ヶ月後までは予約の登録ができません。', $result);

        // ちょうど1ヶ月後（予約可能）
        $oneMonthLater = (new FrozenTime($current))->modify('+1 month')->format('Y-m-d');
        $result = $this->invokeMethod($this->Controller, 'validateReservationDate', [$oneMonthLater]);
        $this->assertTrue($result);

        // 1ヶ月より後（予約可能）
        $overMonth = (new FrozenTime($current))->modify('+1 month +1 day')->format('Y-m-d');
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
        $this->session([
            'Auth' => [
                'i_id_user' => 1,
            ]
        ]);
        $this->get('/t-reservation-info/index');
        $this->assertResponseOk();
        $this->assertResponseContains('mealDataArray');
    }

    /**
     * viewアクションのテスト（正常系）
     */
    public function testViewSuccess()
    {
        $date = (new FrozenDate('+1 month'))->format('Y-m-d');
        $this->get('/t-reservation-info/view?date=' . $date);
        $this->assertResponseOk();
    }

    /**
     * viewアクションのテスト（日付パラメータなし）
     */
    public function testViewNoDate()
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->get('/t-reservation-info/view');
    }

    /**
     * eventsアクションのテスト
     */
    public function testEvents()
    {
        $this->get('/t-reservation-info/events');
        $this->assertResponseOk();
        $this->assertHeader('Content-Type', 'application/json');
    }

    /**
     * getUsersByRoomForBulkアクションのテスト
     */
    public function testGetUsersByRoomForBulk()
    {
        // 有効なルームIDでテスト
        $this->get('/t-reservation-info/getUsersByRoomForBulk/1');
        $this->assertResponseOk();
        $this->assertHeader('Content-Type', 'application/json');

        // レスポンスのJSONを解析
        $responseJson = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('users', $responseJson);

        // 無効なルームIDでテスト（ユーザーが存在しない場合）
        $this->get('/t-reservation-info/getUsersByRoomForBulk/999');
        $this->assertResponseOk(); // エラーではなく空の結果を返すべき
        $responseJson = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('users', $responseJson);
        $this->assertEmpty($responseJson['users']);
    }

    /**
     * bulkAddFormアクションのテスト（正常系）
     */
    public function testBulkAddFormSuccess()
    {
        // ユーザー認証モック
        $this->session([
            'Auth' => [
                'i_id_user' => 1,
            ]
        ]);

        $date = (new FrozenDate('+1 month'))->format('Y-m-d');
        $this->get('/t-reservation-info/bulk-add-form?date=' . $date);
        $this->assertResponseOk();
        $this->assertResponseContains('dates');
        $this->assertResponseContains('rooms');
    }

    /**
     * bulkAddFormアクションのテスト（日付なし）
     */
    public function testBulkAddFormNoDate()
    {
        // ユーザー認証モック
        $this->session([
            'Auth' => [
                'i_id_user' => 1,
            ]
        ]);

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
        $this->session([
            'Auth' => [
                'i_id_user' => 1,
                'c_user_name' => 'テストユーザー'
            ]
        ]);

        $date = (new FrozenDate('+2 months'))->format('Y-m-d');

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
        $this->assertArrayHasKey('status', $responseJson);
    }

    /**
     * bulkAddSubmitアクションのテスト（集団予約）
     */
    public function testBulkAddSubmitGroup()
    {
        // ユーザー認証モック
        $this->session([
            'Auth' => [
                'i_id_user' => 1,
                'c_user_name' => 'テストユーザー'
            ]
        ]);

        $date = (new FrozenDate('+2 months'))->format('Y-m-d');

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
        $this->assertArrayHasKey('status', $responseJson);
    }

    /**
     * bulkAddSubmitアクションのテスト（予約タイプなし）
     */
    public function testBulkAddSubmitNoType()
    {
        // ユーザー認証モック
        $this->session([
            'Auth' => [
                'i_id_user' => 1,
                'c_user_name' => 'テストユーザー'
            ]
        ]);

        $data = []; // 予約タイプなし

        $this->post('/t-reservation-info/bulk-add-submit', $data);
        $this->assertResponseOk();
        $this->assertHeader('Content-Type', 'application/json');

        // レスポンスのJSONを解析
        $responseJson = json_decode((string)$this->_response->getBody(), true);
        $this->assertArrayHasKey('status', $responseJson);
        $this->assertEquals('error', $responseJson['status']);
        $this->assertArrayHasKey('message', $responseJson);
    }

    /**
     * bulkAddSubmitアクションのテスト（個人予約・日付なし）
     */
    public function testBulkAddSubmitPersonalNoDates()
    {
        // ユーザー認証モック
        $this->session([
            'Auth' => [
                'i_id_user' => 1,
                'c_user_name' => 'テストユーザー'
            ]
        ]);

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
        $this->assertArrayHasKey('status', $responseJson);
        // 日付がないのでエラーになるはず
        $this->assertEquals('error', $responseJson['status']);
    }

    /**
     * bulkAddSubmitアクションのテスト（個人予約・食事タイプなし）
     */
    public function testBulkAddSubmitPersonalNoMeals()
    {
        // ユーザー認証モック
        $this->session([
            'Auth' => [
                'i_id_user' => 1,
                'c_user_name' => 'テストユーザー'
            ]
        ]);

        $date = (new FrozenDate('+2 months'))->format('Y-m-d');

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
        $this->assertArrayHasKey('status', $responseJson);
        // 食事タイプがないのでエラーになるはず
        $this->assertEquals('error', $responseJson['status']);
    }

    /**
     * bulkAddSubmitアクションのテスト（集団予約・部屋IDなし）
     */
    public function testBulkAddSubmitGroupNoRoom()
    {
        // ユーザー認証モック
        $this->session([
            'Auth' => [
                'i_id_user' => 1,
                'c_user_name' => 'テストユーザー'
            ]
        ]);

        $date = (new FrozenDate('+2 months'))->format('Y-m-d');

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
        $this->assertArrayHasKey('status', $responseJson);
        // 部屋IDがないのでエラーになるはず
        $this->assertEquals('error', $responseJson['status']);
    }

    /**
     * formatDuplicateMessageメソッドのテスト
     */
    public function testFormatDuplicateMessage()
    {
        $duplicates = [
            '2024-10-01' => [
                'morning' => ['ユーザー1', 'ユーザー2'],
                'noon' => ['ユーザー1']
            ],
            '2024-10-02' => [
                'night' => ['ユーザー3']
            ]
        ];

        $result = $this->invokeMethod($this->Controller, 'formatDuplicateMessage', [$duplicates]);

        $this->assertStringContainsString('2024-10-01', $result);
        $this->assertStringContainsString('朝', $result);
        $this->assertStringContainsString('昼', $result);
        $this->assertStringContainsString('ユーザー1', $result);
        $this->assertStringContainsString('ユーザー2', $result);
        $this->assertStringContainsString('2024-10-02', $result);
        $this->assertStringContainsString('夜', $result);
        $this->assertStringContainsString('ユーザー3', $result);
    }
}
