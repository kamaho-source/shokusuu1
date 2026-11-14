<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * App\Controller\MNoticeController のテスト（お知らせ管理）。
 *
 * 管理者(i_admin=1)・システム管理者(i_admin=3)のみ操作でき、一般職員は一覧へ/ダッシュボードへ戻される。
 */
class MNoticeControllerTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.MNotice',
        'app.MUserInfo',
        'app.TAuditLog',
    ];

    private function login(int $admin): void
    {
        $this->session([
            'Auth' => [
                'i_id_user' => $admin === 3 ? 4 : ($admin === 1 ? 1 : 2),
                'c_login_account' => $admin === 3 ? 'system_admin_user' : ($admin === 1 ? 'admin_user' : 'staff_user'),
                'c_user_name' => 'テストユーザー',
                'i_admin' => $admin,
                'i_user_level' => 0,
                'i_id_room' => 1,
            ],
        ]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        // コントローラーの beforeFilter と同じ（プレーン HTML 入力でフォームヘルパーが追跡しない項目）
        $this->setUnlockedFields(['i_importance', 'i_type', 'i_modal', 'd_start', 'd_end']);
    }

    private function createNotice(string $title = '既存のお知らせ', int $type = 0, int $modal = 0, ?string $end = null): int
    {
        $table = $this->getTableLocator()->get('MNotice');
        $notice = $table->newEmptyEntity();
        $notice = $table->patchEntity($notice, [
            'c_title' => $title,
            'c_body' => '本文',
            'i_importance' => 0,
            'i_type' => $type,
            'i_modal' => $modal,
            'd_end' => $end,
        ]);
        $notice->c_create_user = 'seed';
        $notice->c_update_user = 'seed';
        $notice->i_id_user_created = 1;
        $table->saveOrFail($notice);

        return (int)$notice->i_id;
    }

    private function noticeCount(): int
    {
        return $this->getTableLocator()->get('MNotice')->find()->count();
    }

    // ----------------------------------------------------------------- index

    public function testIndex_admin_listsNotices(): void
    {
        $this->createNotice('一覧に出るお知らせ');
        $this->login(1);

        $this->get('/MNotice');

        $this->assertResponseOk();
        $this->assertResponseContains('一覧に出るお知らせ');
    }

    /** 掲載期間は Y/m/d で出す（以前は地域設定に依存する「10/31/26」の形だった） */
    public function testIndex_showsPeriodAsYmd(): void
    {
        $table = $this->getTableLocator()->get('MNotice');
        $notice = $table->newEntity(['c_title' => '期間あり', 'i_importance' => 0, 'i_type' => 0, 'd_start' => '2026-10-01', 'd_end' => '2026-10-31']);
        $notice->c_create_user = 'seed';
        $table->saveOrFail($notice);
        $this->login(1);

        $this->get('/MNotice');

        $this->assertResponseContains('2026/10/01 〜 2026/10/31');
    }

    public function testIndex_staff_isRedirectedToDashboard(): void
    {
        $this->login(0);

        $this->get('/MNotice');

        $this->assertRedirect('/');
        $this->assertFlashMessage('この機能は管理者のみ利用できます。', 'flash');
    }

    public function testIndex_guest_isRedirectedToLogin(): void
    {
        $this->get('/MNotice');
        $this->assertRedirectContains('/MUserInfo/login');
    }

    // ---------------------------------------------------------------- modals

    public function testModals_returnsOnlyActiveModalNotices(): void
    {
        $this->createNotice('モーダルで出す', 0, 1);
        $this->createNotice('カードだけ', 0, 0);
        $this->createNotice('期間が過ぎたモーダル', 0, 1, '2000-01-01');
        $this->login(0);

        $this->get('/MNotice/modals');

        $this->assertResponseOk();
        $this->assertContentType('application/json');
        $titles = array_column(json_decode((string)$this->_response->getBody(), true), 'title');
        $this->assertSame(['モーダルで出す'], $titles);
    }

    public function testModals_ordersByImportanceThenNewestAndHonorsPeriod(): void
    {
        $table = $this->getTableLocator()->get('MNotice');
        $make = function (string $title, int $importance, ?string $start = null) use ($table): void {
            $n = $table->newEntity(['c_title' => $title, 'i_importance' => $importance, 'i_type' => 0, 'i_modal' => 1, 'd_start' => $start]);
            $n->c_create_user = 'seed';
            $table->saveOrFail($n);
        };
        $make('通常・先', 0);
        $make('通常・後', 0);
        $make('重要', 1);
        $make('明日から', 1, '2999-01-01');
        $this->login(0);

        $this->get('/MNotice/modals');

        $items = json_decode((string)$this->_response->getBody(), true);
        $this->assertSame(['重要', '通常・後', '通常・先'], array_column($items, 'title'), '重要度の降順、同じなら新しい順。開始前は出ない');
        $this->assertSame(['id', 'title', 'body', 'high'], array_keys($items[0]));
        $this->assertTrue($items[0]['high']);
    }

    public function testModals_guest_isRedirectedToLogin(): void
    {
        $this->get('/MNotice/modals');
        $this->assertRedirectContains('/MUserInfo/login');
    }

    public function testAdd_systemAdmin_canSetModal(): void
    {
        $this->login(3);

        $this->post('/MNotice/add', ['c_title' => 'モーダル予告', 'i_importance' => 1, 'i_type' => 0, 'i_modal' => 1]);

        $this->assertRedirect(['action' => 'index']);
        $this->assertSame(1, (int)$this->getTableLocator()->get('MNotice')->find()->firstOrFail()->i_modal);
    }

    public function testAdd_admin_cannotSetModal(): void
    {
        $this->login(1);

        $this->post('/MNotice/add', ['c_title' => '管理者の予告', 'i_importance' => 1, 'i_type' => 0, 'i_modal' => 1]);

        $this->assertRedirect(['action' => 'index']);
        $this->assertSame(0, (int)$this->getTableLocator()->get('MNotice')->find()->firstOrFail()->i_modal);
    }

    public function testEdit_systemAdmin_canTurnModalOffAndOn(): void
    {
        $id = $this->createNotice('モーダル', 0, 1);
        $this->login(3);

        $this->post('/MNotice/edit/' . $id, ['c_title' => 'モーダル', 'i_importance' => 0, 'i_type' => 0]);
        $this->assertSame(0, (int)$this->getTableLocator()->get('MNotice')->get($id)->i_modal, 'チェックを外すと 0');

        $this->post('/MNotice/edit/' . $id, ['c_title' => 'モーダル', 'i_importance' => 0, 'i_type' => 0, 'i_modal' => 1]);
        $this->assertSame(1, (int)$this->getTableLocator()->get('MNotice')->get($id)->i_modal, 'チェックを入れると 1');
    }

    public function testEdit_admin_keepsModal(): void
    {
        $id = $this->createNotice('モーダル', 0, 1);
        $this->login(1);

        $this->post('/MNotice/edit/' . $id, ['c_title' => '題名だけ変更', 'i_importance' => 0, 'i_type' => 0]);

        $this->assertRedirect(['action' => 'index']);
        $this->assertSame(1, (int)$this->getTableLocator()->get('MNotice')->get($id)->i_modal);
    }

    // ------------------------------------------------------------------- add

    public function testAdd_get_showsForm(): void
    {
        $this->login(1);
        $this->get('/MNotice/add');
        $this->assertResponseOk();
    }

    public function testAdd_staff_isRejected(): void
    {
        $this->login(0);

        $this->post('/MNotice/add', ['c_title' => '不正登録', 'i_importance' => 0, 'i_type' => 0]);

        $this->assertRedirect(['action' => 'index']);
        $this->assertSame(0, $this->noticeCount());
    }

    public function testAdd_admin_savesNoticeAndForcesNormalType(): void
    {
        $this->login(1);

        $this->post('/MNotice/add', [
            'c_title' => '  新しいお知らせ  ',
            'c_body' => 'お知らせ本文',
            'd_start' => '2026-10-01',
            'd_end' => '2026-10-31',
            'i_importance' => 1,
            'i_type' => 1,
        ]);

        $this->assertRedirect(['action' => 'index']);
        $this->assertFlashMessage('お知らせを登録しました。', 'flash');
        $saved = $this->getTableLocator()->get('MNotice')->find()->firstOrFail();
        $this->assertSame('新しいお知らせ', $saved->c_title);
        $this->assertSame('お知らせ本文', $saved->c_body);
        $this->assertSame('2026-10-31', $saved->d_end->format('Y-m-d'));
        $this->assertSame(1, (int)$saved->i_importance);
        $this->assertSame(0, (int)$saved->i_type, '管理者はリリースノート種別を指定できない');
        $this->assertSame(1, (int)$saved->i_id_user_created);

        $log = $this->getTableLocator()->get('TAuditLog')->find()
            ->where(['c_target_table' => 'm_notice'])
            ->firstOrFail();
        $this->assertSame((string)$saved->i_id, $log->c_target_id, '監査ログの対象IDにお知らせのIDが入る');
    }

    public function testAdd_systemAdmin_canSaveReleaseNote(): void
    {
        $this->login(3);

        $this->post('/MNotice/add', ['c_title' => 'リリースノート', 'i_importance' => 0, 'i_type' => 1]);

        $this->assertRedirect(['action' => 'index']);
        $saved = $this->getTableLocator()->get('MNotice')->find()->firstOrFail();
        $this->assertSame(1, (int)$saved->i_type);
        $this->assertNull($saved->c_body);
    }

    /**
     * @return array<string, array{array<string, mixed>, string}>
     */
    public static function invalidAddInputs(): array
    {
        return [
            '重要度が範囲外' => [['c_title' => 'a', 'i_importance' => 5, 'i_type' => 0], '重要度の値が不正です。'],
            '種別が範囲外' => [['c_title' => 'a', 'i_importance' => 0, 'i_type' => 9], '種別の値が不正です。'],
            '開始日の形式不正' => [['c_title' => 'a', 'i_importance' => 0, 'i_type' => 0, 'd_start' => '2026/10/01'], '開始日の形式が不正です。'],
            '終了日の形式不正' => [['c_title' => 'a', 'i_importance' => 0, 'i_type' => 0, 'd_end' => '10-31'], '終了日の形式が不正です。'],
            // 以前は保存でき、いつまでも掲載されないお知らせになっていた
            '終了日が開始日より前' => [['c_title' => 'a', 'i_importance' => 0, 'i_type' => 0, 'd_start' => '2026-10-31', 'd_end' => '2026-10-01'], '終了日は開始日以降の日付を指定してください。'],
        ];
    }

    /**
     * @dataProvider invalidAddInputs
     * @param array<string, mixed> $data
     */
    public function testAdd_invalidInput_isRejectedWithoutSaving(array $data, string $message): void
    {
        $this->login(1);

        $this->post('/MNotice/add', $data);

        $this->assertResponseOk();
        $this->assertResponseContains($message);
        $this->assertSame(0, $this->noticeCount());
    }

    public function testAdd_emptyTitle_failsValidation(): void
    {
        $this->login(1);

        $this->post('/MNotice/add', ['c_title' => '   ', 'i_importance' => 0, 'i_type' => 0]);

        $this->assertResponseOk();
        $this->assertResponseContains('お知らせの登録に失敗しました。入力内容を確認してください。');
        $this->assertSame(0, $this->noticeCount());
    }

    // ------------------------------------------------------------------ edit

    public function testEdit_get_showsForm(): void
    {
        $id = $this->createNotice('編集前');
        $this->login(1);

        $this->get('/MNotice/edit/' . $id);

        $this->assertResponseOk();
        $this->assertResponseContains('編集前');
    }

    public function testEdit_unknownId_returns404(): void
    {
        $this->login(1);
        $this->disableErrorHandlerMiddleware();

        $this->expectException(\Cake\Http\Exception\NotFoundException::class);
        $this->get('/MNotice/edit/9999');
    }

    public function testEditAndDelete_unknownId_return404(): void
    {
        $this->login(1);
        $this->post('/MNotice/edit/9999', ['c_title' => 'a', 'i_importance' => 0, 'i_type' => 0]);
        $this->assertResponseCode(404);
        $this->post('/MNotice/delete/9999');
        $this->assertResponseCode(404);
    }

    public function testEdit_staff_isRejected(): void
    {
        $id = $this->createNotice('編集前');
        $this->login(0);

        $this->post('/MNotice/edit/' . $id, ['c_title' => '書き換え', 'i_importance' => 0, 'i_type' => 0]);

        $this->assertRedirect(['action' => 'index']);
        $this->assertSame('編集前', $this->getTableLocator()->get('MNotice')->get($id)->c_title);
    }

    public function testEdit_admin_updatesButKeepsExistingType(): void
    {
        $id = $this->createNotice('編集前', 1);
        $this->login(1);

        $this->post('/MNotice/edit/' . $id, [
            'c_title' => '  編集後  ',
            'c_body' => '',
            'i_importance' => 1,
            'i_type' => 0,
        ]);

        $this->assertRedirect(['action' => 'index']);
        $this->assertFlashMessage('お知らせを更新しました。', 'flash');
        $saved = $this->getTableLocator()->get('MNotice')->get($id);
        $this->assertSame('編集後', $saved->c_title);
        $this->assertNull($saved->c_body);
        $this->assertSame(1, (int)$saved->i_importance);
        $this->assertSame(1, (int)$saved->i_type, '管理者は既存の種別を変更できない');
        $this->assertSame('管理者ユーザー', $saved->c_update_user, '更新した人（ログイン中の利用者）を残す');

        $log = $this->getTableLocator()->get('TAuditLog')->find()->where(['c_action' => 'notice_edit'])->firstOrFail();
        $this->assertSame([(string)$id, 'm_notice'], [$log->c_target_id, $log->c_target_table], '更新も監査ログに残す');
    }

    public function testEdit_systemAdmin_canChangeType(): void
    {
        $id = $this->createNotice('編集前', 0);
        $this->login(3);

        $this->post('/MNotice/edit/' . $id, ['c_title' => '編集前', 'i_importance' => 0, 'i_type' => 1]);

        $this->assertRedirect(['action' => 'index']);
        $this->assertSame(1, (int)$this->getTableLocator()->get('MNotice')->get($id)->i_type);
    }

    /**
     * @dataProvider invalidAddInputs
     * @param array<string, mixed> $data
     */
    public function testEdit_invalidInput_isRejectedWithoutUpdating(array $data, string $message): void
    {
        $id = $this->createNotice('編集前');
        $this->login(1);

        $this->post('/MNotice/edit/' . $id, $data);

        $this->assertResponseOk();
        $this->assertResponseContains($message);
        $this->assertSame('編集前', $this->getTableLocator()->get('MNotice')->get($id)->c_title);
    }

    public function testEdit_emptyTitle_failsValidation(): void
    {
        $id = $this->createNotice('編集前');
        $this->login(1);

        $this->post('/MNotice/edit/' . $id, ['c_title' => '', 'i_importance' => 0, 'i_type' => 0]);

        $this->assertResponseOk();
        $this->assertResponseContains('お知らせの更新に失敗しました。入力内容を確認してください。');
        $this->assertSame('編集前', $this->getTableLocator()->get('MNotice')->get($id)->c_title);
    }

    // ---------------------------------------------------------------- delete

    public function testDelete_admin_removesNotice(): void
    {
        $id = $this->createNotice();
        $this->login(1);

        $this->post('/MNotice/delete/' . $id);

        $this->assertRedirect(['action' => 'index']);
        $this->assertFlashMessage('お知らせを削除しました。', 'flash');
        $this->assertSame(0, $this->noticeCount());

        $log = $this->getTableLocator()->get('TAuditLog')->find()
            ->where(['c_target_table' => 'm_notice', 'c_action' => 'notice_delete'])
            ->firstOrFail();
        $this->assertSame((string)$id, $log->c_target_id, '監査ログの対象IDに削除したお知らせのIDが入る');
    }

    public function testDelete_staff_isRejected(): void
    {
        $id = $this->createNotice();
        $this->login(0);

        $this->post('/MNotice/delete/' . $id);

        $this->assertRedirect(['action' => 'index']);
        $this->assertSame(1, $this->noticeCount());
    }

    public function testDelete_getMethod_isNotAllowed(): void
    {
        $id = $this->createNotice();
        $this->login(1);

        $this->get('/MNotice/delete/' . $id);

        $this->assertResponseCode(405);
        $this->assertSame(1, $this->noticeCount());
    }
}
