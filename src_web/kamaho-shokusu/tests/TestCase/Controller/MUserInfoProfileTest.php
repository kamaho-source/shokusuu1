<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * ユーザーの新規登録・詳細・編集で、性別・年代・年齢・役職・職員IDを同じように扱えること（#824）。
 */
class MUserInfoProfileTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.MUserInfo',
        'app.MUserGroup',
        'app.MRoomInfo',
        'app.TAuditLog',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        // user 2（staff_user）: 女性・中学年・9歳・児童
        $this->getTableLocator()->get('MUserInfo')->updateAll(
            ['i_user_gender' => 2, 'i_user_rank' => 3, 'i_user_age' => 9, 'i_user_level' => 1, 'i_id_staff' => null],
            ['i_id_user' => 2]
        );
    }

    private function loginAs(int $userId, int $iAdmin): void
    {
        // 権限はセッションではなく DB の値で判定される
        $this->getTableLocator()->get('MUserInfo')->updateAll(['i_admin' => $iAdmin], ['i_id_user' => $userId]);
        $this->session(['Auth' => [
            'i_id_user' => $userId, 'c_login_account' => $userId === 1 ? 'admin_user' : 'staff_user',
            'c_user_name' => 'テスト', 'i_admin' => $iAdmin, 'i_user_level' => 0, 'i_id_room' => 1,
        ]]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
    }

    private function postEdit(int $userId, array $data): void
    {
        $this->post('/MUserInfo/edit/' . $userId, $data + [
            'c_login_account' => 'staff_user',
            'c_user_name'     => '職員ユーザー',
            'rooms'           => [1 => '1'],
        ]);
    }

    private function user(int $id): \App\Infrastructure\Persistence\Entity\MUserInfo
    {
        return $this->getTableLocator()->get('MUserInfo')->get($id);
    }

    public function testViewShowsAllRegistrationFields(): void
    {
        $this->loginAs(1, 1);
        $this->get('/MUserInfo/view/2');

        $this->assertResponseOk();
        foreach (['性別', '女性', '年代', '中学年', '年齢', '9歳', '役職', '児童'] as $text) {
            $this->assertResponseContains($text);
        }
    }

    public function testViewShowsUnsetForMissingValues(): void
    {
        $this->getTableLocator()->get('MUserInfo')->updateAll(
            ['i_user_gender' => null, 'i_user_rank' => null, 'i_user_age' => null, 'i_user_level' => null],
            ['i_id_user' => 2]
        );
        $this->loginAs(1, 1);
        $this->get('/MUserInfo/view/2');

        $this->assertResponseOk();
        $body = (string)$this->_response->getBody();
        foreach (['性別', '年代', '年齢', '役職'] as $label) {
            $this->assertMatchesRegularExpression('#<th>' . $label . '</th>\s*<td>未設定</td>#u', $body, $label);
        }
    }

    public function testAdminCanEditProfileFields(): void
    {
        $this->loginAs(1, 1);
        $this->postEdit(2, ['i_user_gender' => '1', 'i_user_rank' => '7', 'i_user_level' => '0', 'i_id_staff' => '900123']);

        $this->assertRedirectContains('/MUserInfo');
        $u = $this->user(2);
        $this->assertSame([1, 7, 0, '900123'], [(int)$u->i_user_gender, (int)$u->i_user_rank, (int)$u->i_user_level, (string)$u->i_id_staff]);
    }

    /** 役職を職員以外にすると職員IDは持たない（新規登録と同じ） */
    public function testStaffIdIsClearedWhenLevelIsNotStaff(): void
    {
        $this->getTableLocator()->get('MUserInfo')->updateAll(['i_user_level' => 0, 'i_id_staff' => '900123'], ['i_id_user' => 2]);
        $this->loginAs(1, 1);
        $this->postEdit(2, ['i_user_gender' => '2', 'i_user_rank' => '3', 'i_user_level' => '1', 'i_id_staff' => '900123']);

        $this->assertRedirectContains('/MUserInfo');
        $this->assertNull($this->user(2)->i_id_staff);
    }

    public function testStaffLevelRequiresStaffId(): void
    {
        $this->loginAs(1, 1);
        $this->postEdit(2, ['i_user_gender' => '2', 'i_user_rank' => '3', 'i_user_level' => '0', 'i_id_staff' => '']);

        $this->assertResponseOk();
        $this->assertSame(1, (int)$this->user(2)->i_user_level);
    }

    /** 職員IDの無い既存の職員でも、名前などは保存できる（職員IDを求めるのは役職を職員に変えたときだけ） */
    public function testExistingStaffWithoutStaffIdCanBeSaved(): void
    {
        $this->getTableLocator()->get('MUserInfo')->updateAll(['i_user_level' => 0, 'i_id_staff' => null], ['i_id_user' => 2]);
        $this->loginAs(1, 1);
        $this->postEdit(2, ['i_user_gender' => '2', 'i_user_rank' => '3', 'i_user_level' => '0', 'i_id_staff' => '', 'c_user_name' => '改名']);

        $this->assertRedirectContains('/MUserInfo');
        $this->assertSame('改名', $this->user(2)->c_user_name);
    }

    /** 性別・年代が未設定のユーザーは「未設定」のまま保存できる */
    public function testUnsetProfileCanBeSavedAsUnset(): void
    {
        $this->getTableLocator()->get('MUserInfo')->updateAll(['i_user_gender' => null, 'i_user_rank' => null], ['i_id_user' => 2]);
        $this->loginAs(1, 1);
        $this->postEdit(2, ['i_user_gender' => '', 'i_user_rank' => '', 'i_user_level' => '1', 'c_user_name' => '改名']);

        $this->assertRedirectContains('/MUserInfo');
        $u = $this->user(2);
        $this->assertSame('改名', $u->c_user_name);
        $this->assertNull($u->i_user_gender);
        $this->assertNull($u->i_user_rank);
    }

    /**
     * 選択肢に無い古い値（役職7・性別0）は、選び直さない限り保存で書き換わらない。
     * 空の選択肢が無いと、ブラウザが先頭の選択肢を選んだ状態になり黙って書き換わっていた。
     */
    public function testUndefinedLegacyValuesAreKept(): void
    {
        $this->getTableLocator()->get('MUserInfo')->updateAll(['i_user_level' => 7, 'i_user_gender' => 0], ['i_id_user' => 2]);
        $this->loginAs(1, 1);

        $this->get('/MUserInfo/edit/2');
        $this->assertResponseContains('定義外の値（7）（変更しない）');

        $this->postEdit(2, ['i_user_gender' => '', 'i_user_rank' => '3', 'i_user_level' => '', 'c_user_name' => '改名']);
        $this->assertRedirectContains('/MUserInfo');
        $u = $this->user(2);
        $this->assertSame([7, 0, '改名'], [(int)$u->i_user_level, (int)$u->i_user_gender, $u->c_user_name]);
    }

    /** 職員IDの列は数値。数字以外は黙って空で保存されるので、エラーにして止める */
    public function testNonNumericStaffIdIsRejected(): void
    {
        $this->loginAs(1, 1);
        $this->postEdit(2, ['i_user_gender' => '2', 'i_user_rank' => '3', 'i_user_level' => '0', 'i_id_staff' => 'S-001']);

        $this->assertResponseOk();
        $this->assertSame(1, (int)$this->user(2)->i_user_level);
    }

    /**
     * 未設定・定義外の値の選択欄は required を付けない。
     * テーブルの検証から required が付くと、名前だけ直す保存をブラウザが止めてしまう。
     */
    public function testProfileSelectsAreNotRequiredInBrowser(): void
    {
        $this->getTableLocator()->get('MUserInfo')->updateAll(['i_user_rank' => null, 'i_user_level' => 7], ['i_id_user' => 2]);
        $this->loginAs(1, 1);
        $this->get('/MUserInfo/edit/2');

        $this->assertResponseOk();
        $this->assertDoesNotMatchRegularExpression('/<select[^>]*name="i_user_(gender|rank|level)"[^>]*required/u', (string)$this->_response->getBody());
    }

    /** 入力の誤りは、その理由だけを出す（「保存に失敗しました」を重ねない） */
    public function testProfileErrorShowsOnlyItsReason(): void
    {
        $this->loginAs(1, 1);
        $this->enableRetainFlashMessages();
        $this->postEdit(2, ['i_user_gender' => '2', 'i_user_rank' => '3', 'i_user_level' => '0', 'i_id_staff' => '']);

        $this->assertFlashMessage('役職が職員のときは職員IDを入力してください。');
        $this->assertCount(1, $this->_flashMessages['flash'] ?? []);
    }

    /** 職員IDの列（int）に入らない桁数は、SQL エラーにせず入力エラーにする */
    public function testTooLongStaffIdIsRejected(): void
    {
        $this->loginAs(1, 1);
        $this->postEdit(2, ['i_user_gender' => '2', 'i_user_rank' => '3', 'i_user_level' => '0', 'i_id_staff' => '99999999999']);

        $this->assertResponseOk();
        $this->assertSame(1, (int)$this->user(2)->i_user_level);
    }

    public function testInvalidOptionValueIsRejected(): void
    {
        $this->loginAs(1, 1);
        $this->postEdit(2, ['i_user_gender' => '9', 'i_user_rank' => '3', 'i_user_level' => '1']);

        $this->assertResponseOk();
        $this->assertSame(2, (int)$this->user(2)->i_user_gender);
    }

    /** 年齢は管理者でも編集画面からは変えられない */
    public function testAgeCannotBeChangedEvenByAdmin(): void
    {
        $this->loginAs(1, 1);
        $this->postEdit(2, ['i_user_gender' => '2', 'i_user_rank' => '3', 'i_user_level' => '1', 'i_user_age' => '40']);

        $this->assertRedirectContains('/MUserInfo');
        $this->assertSame(9, (int)$this->user(2)->i_user_age);
    }

    /** 管理者以外が自分を編集するとき、性別は変えられるが、年代・役職・年齢・権限は送っても変わらない。児童は職員IDも持たない */
    public function testNonAdminSelfEditIgnoresProfileAndPrivilegeFields(): void
    {
        $this->loginAs(2, 0);
        $this->postEdit(2, [
            'i_user_gender' => '1', 'i_user_rank' => '7', 'i_user_level' => '0', 'i_id_staff' => '999',
            'i_user_age' => '40', 'i_admin' => '3', 'i_del_flag' => '1', 'i_enable' => '1',
            'c_user_name' => '名前は変えられる',
        ]);

        $this->assertRedirectContains('/MUserInfo');
        $u = $this->user(2);
        $this->assertSame('名前は変えられる', $u->c_user_name);
        $this->assertSame([1, 3, 1, null, 9, 0, 0], [
            (int)$u->i_user_gender, (int)$u->i_user_rank, (int)$u->i_user_level, $u->i_id_staff,
            (int)$u->i_user_age, (int)$u->i_admin, (int)$u->i_del_flag,
        ]);
    }

    public function testEditScreenShowsSelectsOnlyForAdmin(): void
    {
        $this->loginAs(1, 1);
        $this->get('/MUserInfo/edit/2');
        $this->assertResponseContains('name="i_user_level"');
        $this->assertResponseNotContains('name="i_user_age"');

        $this->loginAs(2, 0);
        $this->get('/MUserInfo/edit/2');
        $this->assertResponseOk();
        $this->assertResponseNotContains('name="i_user_level"');
        $this->assertResponseNotContains('name="i_user_rank"');
        $this->assertResponseContains('name="i_user_gender"');
        $this->assertResponseNotContains('name="i_id_staff"'); // 児童は職員IDを持たない
        $this->assertResponseContains('児童');
        $this->assertResponseContains('9歳');
    }

    /** 職員は自分の職員IDを変えられる。空なら変えず、不正な値は拒否する */
    public function testStaffCanChangeOwnStaffId(): void
    {
        $this->getTableLocator()->get('MUserInfo')->updateAll(['i_user_level' => 0, 'i_id_staff' => 100], ['i_id_user' => 2]);
        $this->loginAs(2, 0);

        $this->get('/MUserInfo/edit/2');
        $this->assertResponseContains('name="i_id_staff"');

        $this->postEdit(2, ['i_id_staff' => '123456']);
        $this->assertRedirectContains('/MUserInfo');
        $this->assertSame(123456, (int)$this->user(2)->i_id_staff);

        $this->postEdit(2, ['i_id_staff' => '']);
        $this->assertSame(123456, (int)$this->user(2)->i_id_staff);

        $this->postEdit(2, ['i_id_staff' => 'abc']);
        $this->assertResponseOk();
        $this->assertResponseContains('職員IDは9桁以内の数字で入力してください。');
        $this->assertSame(123456, (int)$this->user(2)->i_id_staff);
        $this->assertSame(0, (int)$this->user(2)->i_user_level);
    }

    /** 新規登録では、ブラウザに保存されたログイン中の人の ID・パスワードを自動入力させない */
    public function testAddScreenDisablesCredentialAutofill(): void
    {
        $this->loginAs(1, 1);
        $this->get('/MUserInfo/add');
        $this->assertResponseOk();
        $this->assertResponseRegExp('/<input[^>]*name="c_login_account"[^>]*autocomplete="off"|<input[^>]*autocomplete="off"[^>]*name="c_login_account"/');
        // type=password があると Chrome が保存済みの候補を出す。伏せ字の文字欄にする
        $this->assertResponseNotContains('type="password"');
        $this->assertResponseRegExp('/<input[^>]*name="c_login_passwd"[^>]*masked-password|<input[^>]*masked-password[^>]*name="c_login_passwd"/');
    }

    /** 変更前→変更後を監査ログに残す */
    public function testEditRecordsChangesInAuditLog(): void
    {
        $this->loginAs(1, 1);
        $this->postEdit(2, ['i_user_gender' => '1', 'i_user_rank' => '3', 'i_user_level' => '1']);

        $log = $this->getTableLocator()->get('TAuditLog')->find()
            ->where(['c_action' => 'user_update'])->orderByDesc('i_id_audit')->firstOrFail();
        $detail = json_decode((string)$log->c_detail, true);
        $this->assertSame([2, 1], array_map('intval', $detail['changes']['i_user_gender']));
        $this->assertArrayNotHasKey('i_user_rank', $detail['changes']);
    }

    /** 新規登録でも、職員IDは9桁以内の数字に限る（SQL エラーの「予期しないエラー」にしない） */
    public function testAddRejectsInvalidStaffId(): void
    {
        $this->loginAs(1, 1);
        $this->enableRetainFlashMessages();
        $table = $this->getTableLocator()->get('MUserInfo');
        foreach (['S-001', '99999999999'] as $i => $staffId) {
            $this->post('/MUserInfo/add', [
                'c_login_account' => 'new_staff_' . $i, 'c_login_passwd' => 'password123', 'c_user_name' => '新規職員',
                'role' => '0', 'staff_id' => $staffId, 'i_user_gender' => '1', 'age_group' => '7', 'age' => '30',
                'MUserGroup' => [['i_id_room' => 1]],
            ]);
            $this->assertResponseContains('職員IDは9桁以内の数字で入力してください。');
            $this->assertSame(0, $table->find()->where(['c_login_account' => 'new_staff_' . $i])->count());
        }
    }

    /** 新規登録で、生年月日は保存しない。年齢・年代・性別が範囲外なら登録しない */
    public function testAddValidatesAgeAndDoesNotNeedBirthDate(): void
    {
        $this->loginAs(1, 1);
        $base = [
            'c_login_passwd' => 'password123', 'c_user_name' => '新規', 'role' => '1',
            'i_user_gender' => '1', 'age_group' => '2', 'MUserGroup' => [['i_id_room' => 1]],
            'birth_year' => '2018', 'birth_month' => '4', 'birth_day' => '2',
        ];
        $table = $this->getTableLocator()->get('MUserInfo');

        $this->post('/MUserInfo/add', $base + ['c_login_account' => 'new_ng', 'age' => '0']);
        $this->assertSame(0, $table->find()->where(['c_login_account' => 'new_ng'])->count());

        $this->post('/MUserInfo/add', $base + ['c_login_account' => 'new_ok', 'age' => '8']);
        $created = $table->find()->where(['c_login_account' => 'new_ok'])->firstOrFail();
        $this->assertSame([8, 2, 1], [(int)$created->i_user_age, (int)$created->i_user_rank, (int)$created->i_user_gender]);
    }
}
