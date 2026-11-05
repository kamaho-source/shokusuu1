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
     * ログイン中に無効化された人は、どの画面でも、?redirect= なしでログイン画面へ転送される。
     * （分割前の本番は、MUserInfo の URL だけ ?redirect= 付きだった。MUserInfoController::beforeFilter() が親の転送を返し忘れていた
     *  結果で、狙った仕様ではないため、分割で揃えた。無効化された人は再ログインできないので、元の画面へ戻る必要がない。）
     *
     * @return array<string, array{string, string}>
     */
    public static function disabledIdentityProvider(): array
    {
        return [
            'MUserInfo'    => ['/MUserInfo/view/1', 'get'],
            'UserPassword' => ['/MUserInfo/generalPasswordReset', 'get'],
            'UserImport'   => ['/MUserInfo/importForm', 'get'],
            'その他'        => ['/TReservationInfo', 'get'],
            // beforeFilter を上書きしているコントローラーも、親の締め出し（転送）をそのまま返す
            'Contacts'     => ['/Contacts', 'get'],
            'MNotice'      => ['/MNotice', 'get'],
            'Pages'        => ['/pages/dashboard', 'get'],
        ];
    }

    /** @dataProvider disabledIdentityProvider */
    public function testDisabledWhileLoggedIn_redirectsToLoginWithoutRedirectParam(string $url, string $method): void
    {
        $this->session(['Auth' => 2]);
        $this->enableCsrfToken();
        ConnectionManager::get('test')->update('m_user_info', ['i_enable' => 1], ['i_id_user' => 2]);

        $method === 'post' ? $this->post($url, []) : $this->get($url);

        $this->assertResponseCode(302);
        $this->assertStringEndsWith('/MUserInfo/login', $this->_response->getHeaderLine('Location'));
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

    /**
     * add 画面は、集団予約で部屋を選んだときの利用者の読み込みを、画面の中だけで完結させる。
     * buildGetUsersByRoomUrl はカレンダー側のスクリプトにしか無く、この画面を単独で開くと未定義（ReferenceError）になっていた。
     */
    public function testAddPage_doesNotDependOnCalendarScriptFunction(): void
    {
        $this->loginAdmin();
        $this->get('/TReservationInfo/add?date=' . Date::today()->addDays(30)->format('Y-m-d'));

        $this->assertResponseOk();
        $this->assertResponseNotContains('= buildGetUsersByRoomUrl(');
        $this->assertResponseContains('window.GET_USERS_BY_ROOM_TPL).replace(');
    }
}
