<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\Datasource\ConnectionManager;
use Cake\I18n\Date;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * #989 のコントローラー分割で、分割前（origin/main）と変わってはいけない転送先・リンク先 URL のテスト。
 */
class SplitUrlParity989Test extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.MRoomInfo',
        'app.MUserInfo',
        'app.MUserGroup',
        'app.TIndividualReservationInfo',
        'app.TReservationInfo',
        'app.TAuditLog',
    ];

    private function loginAdmin(): void
    {
        $this->session(['Auth' => [
            'i_id_user' => 1, 'c_login_account' => 'admin_user', 'c_user_name' => 'テスト1',
            'i_admin' => 1, 'i_user_level' => 0, 'i_id_room' => 1,
        ]]);
        $this->enableCsrfToken();
    }

    /**
     * ログイン中に無効化された人は、MUserInfo の URL で届いていた画面（分割後は別コントローラーでも）では、
     * ログイン画面へ ?redirect= 付きで転送される（分割前、MUserInfoController::beforeFilter() が
     * AppController の転送を返さず、認証ミドルウェアの転送に任せていたため）。他のコントローラーは付かない。
     *
     * @return array<string, array{string, string, string}>
     */
    public static function disabledIdentityProvider(): array
    {
        return [
            'MUserInfo'    => ['/MUserInfo/view/1', 'get', '/MUserInfo/login?redirect=%2FMUserInfo%2Fview%2F1'],
            'UserPassword' => ['/MUserInfo/generalPasswordReset', 'get', '/MUserInfo/login?redirect=%2FMUserInfo%2FgeneralPasswordReset'],
            'UserImport'   => ['/MUserInfo/importForm', 'get', '/MUserInfo/login?redirect=%2FMUserInfo%2FimportForm'],
            'その他'        => ['/TReservationInfo', 'get', '/MUserInfo/login'],
        ];
    }

    /** @dataProvider disabledIdentityProvider */
    public function testDisabledWhileLoggedIn_redirectKeepsOriginalUrlLikeBeforeSplit(string $url, string $method, string $expected): void
    {
        $this->session(['Auth' => 2]);
        $this->enableCsrfToken();
        ConnectionManager::get('test')->update('m_user_info', ['i_enable' => 1], ['i_id_user' => 2]);

        $method === 'post' ? $this->post($url, []) : $this->get($url);

        $this->assertResponseCode(302);
        $this->assertStringEndsWith($expected, $this->_response->getHeaderLine('Location'));
        $this->assertSessionNotHasKey('Auth');
    }

    /** 自分のパスワード変更フォームは、分割前と同じ /m-user-info/general-password-reset へ送り、UserPassword に届く */
    public function testGeneralPasswordResetForm_postsToTheSameUrlAsBeforeSplit(): void
    {
        $this->loginAdmin();
        $this->get('/MUserInfo/generalPasswordReset');
        $this->assertResponseOk();
        $this->assertResponseContains('action="/m-user-info/general-password-reset"');

        $this->get('/m-user-info/general-password-reset');
        $this->assertResponseOk();
        $this->assertSame('UserPassword', $this->_controller->getName());
        $this->assertSame('generalPasswordReset', $this->_controller->getRequest()->getParam('action'));
    }

    /**
     * add 画面の「週の一括予約」は、開けるリンクを出す。
     * 分割前（origin/main）は /t-reservation-info/bulk-add-form を出していたが、これは届かず 404 になる（本番の既存の不具合）。
     * 分割後は /TReservationInfo/bulk-add-form を出し、ReservationBulk に届く。
     */
    public function testAddPage_bulkLinkOpensTheBulkForm(): void
    {
        $this->loginAdmin();
        // 「週の一括予約」は月曜の日付のときだけ出る
        $date = Date::today()->addDays(30)->modify('next monday')->format('Y-m-d');
        $this->get('/TReservationInfo/add?date=' . $date);
        $this->assertResponseOk();
        $this->assertResponseContains('href="/TReservationInfo/bulk-add-form?date=' . $date . '"');

        $this->get('/TReservationInfo/bulk-add-form?date=' . $date);
        $this->assertResponseOk();
        $this->assertSame('ReservationBulk', $this->_controller->getName());
        $this->assertSame('bulkAddForm', $this->_controller->getRequest()->getParam('action'));
    }
}
