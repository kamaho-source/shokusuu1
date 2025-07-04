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
}