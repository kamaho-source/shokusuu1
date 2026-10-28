<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * 存在しない・他人の問い合わせへの返信が 500 にならず、一覧へ戻ること。
 */
class ContactsControllerReplyTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.MUserInfo',
        'app.TContacts',
        'app.TContactReplies',
    ];

    public function testReply_unknownContact_redirectsToIndexNotServerError(): void
    {
        $this->session(['Auth' => [
            'i_id_user' => 3, 'c_login_account' => 'non_staff_user', 'c_user_name' => 'テスト3',
            'i_admin' => 0, 'i_user_level' => 1, 'i_id_room' => 1,
        ]]);
        $this->enableCsrfToken();
        $this->enableSecurityToken();

        $this->post('/Contacts/reply/999999', ['reply_body' => 'テスト']);

        $this->assertRedirect(['action' => 'index']);
        $this->assertFlashMessage('指定されたお問い合わせが見つかりません。', 'flash');
    }
}
