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
