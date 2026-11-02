<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Controller\MMealPriceInfoController;
use Cake\Core\Configure;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * App\Controller\MMealPriceInfoController Test Case
 *
 * @uses \App\Controller\MMealPriceInfoController
 */
class MMealPriceInfoControllerTest extends TestCase
{
    use IntegrationTestTrait;

    /**
     * Fixtures
     *
     * @var list<string>
     */
    protected array $fixtures = [
        'app.MMealPriceInfo',
        'app.MUserInfo',
        'app.TAuditLog',
        'app.TIndividualReservationInfo',
    ];

    public function setUp(): void
    {
        parent::setUp();
        Configure::write('debug', true);
    }

    public function tearDown(): void
    {
        Configure::write('debug', false);
        parent::tearDown();
    }

    /**
     * Test index method
     *
     * @return void
     * @uses \App\Controller\MMealPriceInfoController::index()
     */
    public function testIndex(): void
    {
        $this->setAuthenticatedSession();
        $this->get('/MMealPriceInfo');
        $this->assertResponseOk();
    }

    /**
     * Test view method
     *
     * @return void
     * @uses \App\Controller\MMealPriceInfoController::view()
     */
    public function testView(): void
    {
        $this->setAuthenticatedSession();
        $this->get('/MMealPriceInfo/view/1');
        $this->assertResponseOk();
    }

    /**
     * Test add method
     *
     * @return void
     * @uses \App\Controller\MMealPriceInfoController::add()
     */
    public function testAdd(): void
    {
        $this->setAuthenticatedSession();
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->post('/MMealPriceInfo/add', [
            'i_fiscal_year' => 2025,
            'i_morning_price' => 320,
            'i_lunch_price' => 520,
            'i_dinner_price' => 620,
            'i_bento_price' => 470,
        ]);
        $this->assertResponseSuccess();
        $this->assertRedirect(['action' => 'index']);
    }

    /**
     * Test edit method
     *
     * @return void
     * @uses \App\Controller\MMealPriceInfoController::edit()
     */
    public function testEdit(): void
    {
        $this->setAuthenticatedSession();
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->post('/MMealPriceInfo/edit/1', [
            'i_morning_price' => 350,
        ]);
        $this->assertResponseSuccess();
        $this->assertRedirect(['action' => 'index']);
    }

    /**
     * Test delete method
     *
     * @return void
     * @uses \App\Controller\MMealPriceInfoController::delete()
     */
    public function testDelete(): void
    {
        $this->setAuthenticatedSession();
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->post('/MMealPriceInfo/delete/1');
        $this->assertResponseSuccess();
        $this->assertRedirect(['action' => 'index']);
    }

    /**
     * 承認機能オフのとき、未承認プレビューのエクスポートは 404 になる。
     * （承認オフ運用では「未承認」という概念が存在しないため）
     */
    public function testExportMealSummaryPreview_returns404WhenApprovalOff(): void
    {
        $original = Configure::read('Features.approval');
        Configure::write('Features.approval', false);
        try {
            $this->setAuthenticatedSession();
            $this->get('/MMealPriceInfo/exportMealSummaryPreview?year=2026&month=10');
            $this->assertResponseCode(404);
        } finally {
            Configure::write('Features.approval', $original);
        }
    }

    /**
     * 管理者以外（子どもアカウント）は控除データを取得できない。
     */
    public function testExportMealSummary_deniedForNonAdmin(): void
    {
        $this->session([
            'Auth' => [
                'i_id_user'       => 3,
                'c_login_account' => 'non_staff_user',
                'c_user_name'     => '児童',
                'i_admin'         => 0,
                'i_user_level'    => 1,
            ],
        ]);
        $this->get('/MMealPriceInfo/exportMealSummary?year=2026&month=10');
        $this->assertResponseCode(403);
    }

    /**
     * 単価一覧・詳細は管理者のみ。ブロック長・職員・児童は閲覧できず、ログイン画面・一覧へ戻される。
     *
     * @return array<string, array{int, int, bool}>
     */
    public static function priceViewProvider(): array
    {
        return [
            '管理者'         => [1, 0, true],
            'システム管理者' => [3, 0, true],
            'ブロック長'     => [2, 0, false],
            '職員'           => [0, 0, false],
            '児童'           => [0, 1, false],
        ];
    }

    /**
     * @dataProvider priceViewProvider
     */
    public function testIndexAndGetMealSummaryOnlyForAdmin(int $iAdmin, int $userLevel, bool $allowed): void
    {
        // 権限はセッション値ではなく DB の値で判定されるため、DB 側を更新する
        $this->getTableLocator()->get('MUserInfo')->updateAll(
            ['i_admin' => $iAdmin, 'i_user_level' => $userLevel],
            ['i_id_user' => 2]
        );
        $this->session(['Auth' => ['i_id_user' => 2, 'c_login_account' => 'staff_user', 'c_user_name' => 'テスト', 'i_admin' => $iAdmin, 'i_user_level' => $userLevel]]);

        $this->get('/MMealPriceInfo');
        $allowed ? $this->assertResponseOk() : $this->assertRedirectContains('/MUserInfo/login');

        $this->get('/MMealPriceInfo/view/1');
        $allowed ? $this->assertResponseOk() : $this->assertRedirectContains('/MMealPriceInfo');

        // 食事控除表は拒否を例外のまま返す（exportMealSummary と同じく 403）
        $this->get('/MMealPriceInfo/GetMealSummary');
        $allowed ? $this->assertResponseOk() : $this->assertResponseCode(403);
    }

    private function postPrice(string $url, array $data): void
    {
        $this->setAuthenticatedSession();
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        $this->enableRetainFlashMessages();
        $this->post($url, $data);
    }

    private function priceCount(): int
    {
        return $this->getTableLocator()->get('MMealPriceInfo')->find()->count();
    }

    /** 監査ログの対象IDに単価のIDが入る（以前は存在しない項目を見ていて、いつも空だった） */
    public function testAddEditDelete_auditLogHasTargetId(): void
    {
        $this->postPrice('/MMealPriceInfo/add', ['i_fiscal_year' => 2025, 'i_morning_price' => 1, 'i_lunch_price' => 2, 'i_dinner_price' => 3, 'i_bento_price' => 4]);
        $id = (string)$this->getTableLocator()->get('MMealPriceInfo')->find()->where(['i_fiscal_year' => 2025])->firstOrFail()->i_id;
        $this->postPrice('/MMealPriceInfo/edit/' . $id, ['i_morning_price' => 9]);
        $this->postPrice('/MMealPriceInfo/delete/' . $id, []);

        $logs = $this->getTableLocator()->get('TAuditLog')->find()->where(['c_target_table' => 'm_meal_price_info'])->orderByAsc('i_id_audit')->all()->toList();
        $this->assertSame(['meal_price_create', 'meal_price_update', 'meal_price_delete'], array_map(fn($l) => $l->c_action, $logs));
        $this->assertSame([$id, $id, $id], array_map(fn($l) => $l->c_target_id, $logs));
    }

    /**
     * 画面の入力欄の決まり（年度は4桁、単価は0以上の整数で必須）をサーバーでも確かめる。同じ年は1行だけ
     *
     * @return array<string, array{array<string, int|string>, string}>
     */
    public static function invalidPriceProvider(): array
    {
        $ok = ['i_fiscal_year' => 2025, 'i_morning_price' => 1, 'i_lunch_price' => 2, 'i_dinner_price' => 3, 'i_bento_price' => 4];

        return [
            '年度が空' => [['i_fiscal_year' => ''] + $ok, '年度は 4 桁の数字で入力してください。'],
            '年度が3桁' => [['i_fiscal_year' => 999] + $ok, '年度は 4 桁の数字で入力してください。'],
            '単価が負' => [['i_lunch_price' => -1] + $ok, '昼食の単価は 0 以上の整数（円）で入力してください。'],
            '単価が空' => [['i_bento_price' => ''] + $ok, '弁当の単価は 0 以上の整数（円）で入力してください。'],
            '単価が上限超え' => [['i_morning_price' => 100000] + $ok, '朝食の単価は 99,999 円以下で入力してください。'],
            '単価が int を超える桁数' => [['i_dinner_price' => '99999999999'] + $ok, '夕食の単価は 99,999 円以下で入力してください。'],
            '同じ年がある' => [['i_fiscal_year' => 2024] + $ok, '2024年の単価はすでに登録されています。登録済みの単価を編集してください。'],
        ];
    }

    /**
     * @dataProvider invalidPriceProvider
     */
    public function testAdd_invalidInput_isRejected(array $data, string $message): void
    {
        $this->postPrice('/MMealPriceInfo/add', $data);

        $this->assertResponseOk();
        $this->assertFlashMessage($message, 'flash');
        $this->assertSame(1, $this->priceCount());
    }

    public function testEdit_toAnotherRowsYear_isRejected(): void
    {
        $this->getTableLocator()->get('MMealPriceInfo')->saveOrFail($this->getTableLocator()->get('MMealPriceInfo')->newEntity(['i_fiscal_year' => 2025, 'i_morning_price' => 1, 'i_lunch_price' => 1, 'i_dinner_price' => 1, 'i_bento_price' => 1]));

        $this->postPrice('/MMealPriceInfo/edit/1', ['i_fiscal_year' => 2025]);

        $this->assertResponseOk();
        $this->assertFlashMessage('2025年の単価はすでに登録されています。登録済みの単価を編集してください。', 'flash');
        $this->assertSame(2024, (int)$this->getTableLocator()->get('MMealPriceInfo')->get(1)->i_fiscal_year);
    }

    /** 上限ちょうど（99,999 円）は登録できる */
    public function testAdd_maxPrice_isSaved(): void
    {
        $this->postPrice('/MMealPriceInfo/add', ['i_fiscal_year' => 2025, 'i_morning_price' => 99999, 'i_lunch_price' => 2, 'i_dinner_price' => 3, 'i_bento_price' => 4]);

        $this->assertSame(2, $this->priceCount());
        $this->assertSame(99999, (int)$this->getTableLocator()->get('MMealPriceInfo')->find()->where(['i_fiscal_year' => 2025])->firstOrFail()->i_morning_price);
    }

    /**
     * 編集でも上限を超える単価は 500 にせず、理由を出して保存しない
     *
     * @return array<string, array{array<string, int|string>, string}>
     */
    public static function tooLargePriceProvider(): array
    {
        return [
            '100000' => [['i_lunch_price' => 100000], '昼食の単価は 99,999 円以下で入力してください。'],
            '99999999999' => [['i_bento_price' => '99999999999'], '弁当の単価は 99,999 円以下で入力してください。'],
        ];
    }

    /**
     * @dataProvider tooLargePriceProvider
     */
    public function testEdit_tooLargePrice_isRejected(array $data, string $message): void
    {
        $this->postPrice('/MMealPriceInfo/edit/1', $data);

        $this->assertResponseOk();
        $this->assertFlashMessage($message, 'flash');
        $row = $this->getTableLocator()->get('MMealPriceInfo')->get(1);
        $this->assertSame([300, 500, 600, 450], [(int)$row->i_morning_price, (int)$row->i_lunch_price, (int)$row->i_dinner_price, (int)$row->i_bento_price]);
    }

    /** 送らなかった項目は今の値のまま */
    public function testEdit_partial_keepsOtherPrices(): void
    {
        $this->postPrice('/MMealPriceInfo/edit/1', ['i_morning_price' => 350]);

        $row = $this->getTableLocator()->get('MMealPriceInfo')->get(1);
        $this->assertSame([2024, 350, 500, 600, 450], [(int)$row->i_fiscal_year, (int)$row->i_morning_price, (int)$row->i_lunch_price, (int)$row->i_dinner_price, (int)$row->i_bento_price]);
    }

    public function testUnknownId_returns404(): void
    {
        $this->setAuthenticatedSession();
        foreach (['/MMealPriceInfo/view/999', '/MMealPriceInfo/edit/999'] as $url) {
            $this->get($url);
            $this->assertResponseCode(404, $url);
        }
    }

    /** 控除表: その年の単価が無いと 422 で理由を返す（以前は控除額が黙って 0 円になっていた）。年・月が不正なら 400 */
    public function testExportMealSummary_requiresPriceOfYearAndValidMonth(): void
    {
        $this->setAuthenticatedSession();
        $this->get('/MMealPriceInfo/exportMealSummary?year=2030&month=1');
        $this->assertResponseCode(422);
        $this->assertSame('2030年の単価が登録されていません。単価マスタに登録してから出力してください。', json_decode((string)$this->_response->getBody(), true)['message']);

        foreach (['year=2024&month=13', 'year=2024&month=0', 'year=0&month=1'] as $q) {
            $this->get('/MMealPriceInfo/exportMealSummary?' . $q);
            $this->assertResponseCode(400, $q);
        }
    }

    /** 控除表: 控除額は単価 × 食数（職員ID のある人ごと） */
    public function testExportMealSummary_totalsByPrice(): void
    {
        $db = \Cake\Datasource\ConnectionManager::get('test');
        $db->execute('UPDATE m_user_info SET i_id_staff = NULL');
        $db->update('m_user_info', ['i_id_staff' => 9002], ['i_id_user' => 2]);
        foreach ([['2024-09-02', 1], ['2024-09-03', 2], ['2024-09-04', 4], ['2024-09-05', 3]] as [$d, $t]) {
            $db->insert('t_individual_reservation_info', ['i_id_user' => 2, 'd_reservation_date' => $d, 'i_reservation_type' => $t, 'i_id_room' => 1, 'eat_flag' => 1, 'i_version' => 1, 'dt_create' => '2024-01-01 00:00:00', 'c_create_user' => 't']);
        }
        $this->setAuthenticatedSession();

        $this->get('/MMealPriceInfo/exportMealSummary?year=2024&month=9');

        $this->assertResponseOk();
        $rows = json_decode((string)$this->_response->getBody(), true)['data']['rows'];
        $this->assertSame([['name' => '職員ユーザー', 'staff_id' => 9002, 'meal_counts' => ['bento' => 1, 'morning' => 1, 'lunch' => 1, 'dinner' => 1], 'total_price' => 300 + 500 + 450 + 600]], $rows);
        $this->assertSame(1, $this->getTableLocator()->get('TAuditLog')->find()->where(['c_action' => 'meal_price_excel_export'])->count(), '出力を監査ログに残す');
    }

    private function setAuthenticatedSession(): void
    {
        $this->session([
            'Auth' => [
                'i_id_user'       => 1,
                'c_login_account' => 'admin_user',
                'c_user_name'     => 'テストユーザー',
                'i_admin'         => 1,
                'i_user_level'    => 0,
                'i_id_room'       => 1,
            ],
        ]);
    }
}
