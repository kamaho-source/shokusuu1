<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\TestSuite\EmailTrait;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * 問い合わせの画面（ContactsController）。
 */
class ContactsControllerTest extends TestCase
{
    use EmailTrait;
    use IntegrationTestTrait;

    protected array $fixtures = ['app.MUserInfo', 'app.TContacts', 'app.TContactReplies'];

    private function login(int $userId, int $admin, bool $formToken = true): void
    {
        $this->session(['Auth' => ['i_id_user' => $userId, 'c_login_account' => 'u' . $userId, 'c_user_name' => 'テスト', 'i_admin' => $admin, 'i_user_level' => 0]]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();
        if (!$formToken) {
            // 送信フォームの項目は FormProtection の固定から外している（ContactsController::beforeFilter と同じ）
            $this->setUnlockedFields(['name', 'email', 'category', 'body']);
        }
        $this->enableRetainFlashMessages();
    }

    public function testIndex_showsOwnContacts(): void
    {
        $this->login(1, 0);
        $this->get('/Contacts');
        $this->assertResponseOk();
        $this->assertSame([1], array_map(static fn($c) => $c->id, $this->viewVariable('myContacts')));
    }

    public function testSubmit_invalid_showsFieldErrorsAndKeepsInput(): void
    {
        $this->login(1, 0, false);
        $this->post('/Contacts', ['category' => 'でたらめ', 'name' => '  名前 ', 'email' => 'x', 'body' => '短い']);

        $this->assertResponseOk();
        $this->assertFlashMessage('入力内容に誤りがあります。確認してください。', 'flash');
        $this->assertResponseContains('カテゴリを選択してください。');
        $this->assertResponseContains('内容は10文字以上で入力してください。');
        $this->assertResponseContains('value="名前"');
        $this->assertSame(2, $this->getTableLocator()->get('TContacts')->find()->count());
    }

    public function testSubmit_valid_savesAndRedirects(): void
    {
        $this->login(1, 0, false);
        $this->post('/Contacts', ['category' => '不具合報告', 'name' => '太郎', 'email' => 'taro@example.com', 'body' => '画面が真っ白になります。']);

        $this->assertRedirect(['action' => 'index']);
        $this->assertSame(3, $this->getTableLocator()->get('TContacts')->find()->count());
        $this->assertMailSentTo('taro@example.com');
    }

    /** 他人の問い合わせには返信できない */
    public function testReply_ownAndOthers(): void
    {
        $this->login(1, 0);
        $this->post('/Contacts/reply/1', ['reply_body' => '追加の質問です']);
        $this->assertFlashMessage('返信を送信しました。', 'flash');

        $this->post('/Contacts/reply/2', ['reply_body' => '他人の問い合わせ']);
        $this->assertFlashMessage('指定されたお問い合わせが見つかりません。', 'flash');
        $this->assertFalse($this->getTableLocator()->get('TContactReplies')->exists(['body' => '他人の問い合わせ']));
    }

    /** ページ番号が 0 以下・数字でないときは 1 ページ目（以前は 500 になっていた） */
    public function testAdminIndex_invalidPage_isFirstPage(): void
    {
        $this->login(1, 1);
        foreach (['0', '-3', 'abc'] as $page) {
            $this->get('/Contacts/adminIndex?page=' . $page);
            $this->assertResponseOk($page);
            $this->assertCount(2, $this->viewVariable('contacts'));
        }
    }

    /** 存在しない問い合わせは 404（以前は画面の見出しが「システムエラー」だった） */
    public function testAdminDetail_unknown_returns404(): void
    {
        $this->login(1, 1);
        $this->get('/Contacts/adminDetail/999');
        $this->assertResponseCode(404);
        $this->assertResponseContains('ページが見つかりません');
    }

    public function testAdminDetail_reply_sendsMailAndRedirects(): void
    {
        $this->login(1, 1);
        $this->post('/Contacts/adminDetail/1', ['reply_body' => 'ご案内します。']);

        $this->assertRedirect(['action' => 'adminDetail', 1]);
        $this->assertTrue($this->getTableLocator()->get('TContactReplies')->exists(['contact_id' => 1, 'body' => 'ご案内します。', 'author_type' => 'admin']));
    }
}
