<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ContactService;
use Cake\Core\Configure;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\Http\Client;
use Cake\Http\Client\Response;
use Cake\Mailer\AbstractTransport;
use Cake\Mailer\Message;
use Cake\Mailer\TransportFactory;
use Cake\TestSuite\EmailTrait;
use Cake\TestSuite\TestCase;

/**
 * ContactService の保存・メール送信・Slack通知・一覧取得のテスト。
 *
 * メールは EmailTrait のデバッグトランスポート、Slack は Http\Client のモックレスポンスで
 * 受け、実際の通信は行わない。
 */
class ContactServiceMailTest extends TestCase
{
    use EmailTrait;

    protected array $fixtures = [
        'app.TContacts',
        'app.TContactReplies',
    ];

    private ContactService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ContactService();
        Configure::write('App.adminEmail', 'ops@example.com');
        putenv('SLACK_CONTACT_WEBHOOK');
        unset($_ENV['SLACK_CONTACT_WEBHOOK'], $_SERVER['SLACK_CONTACT_WEBHOOK']);
    }

    protected function tearDown(): void
    {
        Configure::delete('App.adminEmail');
        Configure::delete('App.inboundReplyDomain');
        putenv('SLACK_CONTACT_WEBHOOK');
        unset($_ENV['SLACK_CONTACT_WEBHOOK'], $_SERVER['SLACK_CONTACT_WEBHOOK']);
        Client::clearMockResponses();
        parent::tearDown();
    }

    /**
     * @return array{category: string, name: string, email: string, body: string}
     */
    private function validInput(string $category = '不具合報告'): array
    {
        return [
            'category' => $category,
            'name' => '  新規 太郎 ',
            'email' => ' new@example.com ',
            'body' => ' 画面が真っ白になります。 ',
        ];
    }

    private function failingTransport(): void
    {
        TransportFactory::drop('default');
        TransportFactory::setConfig('default', new class (['className' => 'Debug']) extends AbstractTransport {
            public function send(Message $message): array
            {
                throw new \RuntimeException('smtp down');
            }
        });
    }

    // ---------------------------------------------------------------- submit

    public function testSubmit_invalidInput_returnsErrorsAndSendsNothing(): void
    {
        $result = $this->service->submit(['category' => 'でたらめ', 'name' => '', 'email' => 'x', 'body' => ''], 1);

        $this->assertFalse($result['success']);
        $errors = $result['entity']->getErrors();
        $this->assertArrayHasKey('category', $errors);
        $this->assertArrayHasKey('name', $errors);
        $this->assertArrayHasKey('body', $errors);
        $this->assertNoMailSent();
        $this->assertSame(2, $this->getTableLocator()->get('TContacts')->find()->count());
    }

    public function testSubmit_valid_savesTrimmedWithTokenAndSendsAutoReplyAndAdminMail(): void
    {
        $result = $this->service->submit($this->validInput(), 7);

        $this->assertTrue($result['success']);
        $entity = $result['entity'];
        $saved = $this->getTableLocator()->get('TContacts')->get($entity->id);
        $this->assertSame('新規 太郎', $saved->name);
        $this->assertSame('new@example.com', $saved->email);
        $this->assertSame('画面が真っ白になります。', $saved->body);
        $this->assertSame(7, (int)$saved->user_id);
        $this->assertSame(32, strlen((string)$saved->reply_token));

        $this->assertMailCount(2);
        $this->assertMailSentTo('new@example.com');
        $this->assertMailSentTo('ops@example.com');
        $this->assertMailSubjectContainsAt(0, '不具合報告を受け付けました');
        $this->assertMailContainsTextAt(0, '担当者が内容を確認し、早急に対応いたします。');
        $this->assertMailSubjectContainsAt(1, '新しいお問い合わせ：不具合報告');
    }

    public function testSubmit_replyToUsesTokenAndConfiguredDomain(): void
    {
        Configure::write('App.inboundReplyDomain', 'in.example.test');

        $result = $this->service->submit($this->validInput('ご意見・ご要望'), null);

        $token = (string)$result['entity']->reply_token;
        $this->assertMailSentWithAt(0, 'reply+' . $token . '@in.example.test', 'replyTo');
        $this->assertMailSubjectContainsAt(0, 'ご意見・ご要望を受け付けました');
    }

    public function testSubmit_adminEmailUnset_skipsAdminMail(): void
    {
        Configure::write('App.adminEmail', 'admin@localhost');

        $result = $this->service->submit($this->validInput('使い方の質問'), 1);

        $this->assertTrue($result['success']);
        $this->assertMailCount(1);
        $this->assertMailSentTo('new@example.com');
    }

    public function testSubmit_mailFailureDoesNotFailSubmission(): void
    {
        $this->failingTransport();

        $result = $this->service->submit($this->validInput(), 1);

        $this->assertTrue($result['success'], 'メール送信に失敗しても問い合わせ自体は保存される');
        $this->assertTrue($this->getTableLocator()->get('TContacts')->exists(['id' => $result['entity']->id]));
    }

    public function testSubmit_postsToSlackWhenWebhookConfigured(): void
    {
        $url = 'https://slack.example.test/hook';
        putenv('SLACK_CONTACT_WEBHOOK=' . $url);
        $posted = [];
        Client::addMockResponse('POST', $url, new Response(['HTTP/1.1 200 OK'], 'ok'), [
            'match' => function ($request) use (&$posted): bool {
                $posted[] = (string)$request->getBody();

                return true;
            },
        ]);

        $result = $this->service->submit($this->validInput(), 1);

        $this->assertTrue($result['success']);
        $this->assertCount(1, $posted);
        $this->assertStringContainsString('新規 太郎', (string)json_decode($posted[0], true)['text']);
    }

    // ------------------------------------------------------- list / detail

    public function testGetListAndGetDetail(): void
    {
        $list = $this->service->getList();
        $this->assertSame([2, 1], array_map(fn($c) => $c->id, $list));
        $this->assertCount(1, $this->service->getList(1, 1));
        $this->assertCount(1, $this->service->getList(2, 1));
        $this->assertSame([], $this->service->getList(3, 1));

        $this->assertSame(1, $this->service->getDetail(1)->id);
        $this->expectException(RecordNotFoundException::class);
        $this->service->getDetail(999);
    }

    // --------------------------------------------------------------- replies

    public function testSendReply_sendsMailFromSupportWithReplyTo(): void
    {
        $result = $this->service->sendReply(1, '  手順をご案内します。  ');

        $this->assertTrue($result['success']);
        $this->assertMailCount(1);
        $this->assertMailSentTo('taro@example.com');
        $this->assertMailSentFrom('support@kamaho-shokusu.jp');
        $this->assertMailSubjectContains('Re: 使い方の質問について');
        $this->assertMailContainsText('手順をご案内します。');
        $this->assertMailSentWith('reply+aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa@reply.kamaho-shokusu.jp', 'replyTo');
    }

    public function testSendReply_emptyBody_returnsValidationErrorsWithoutMail(): void
    {
        $result = $this->service->sendReply(1, '   ');

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('body', $result['errors']);
        $this->assertNoMailSent();
    }

    public function testSendReply_unknownContact_throws(): void
    {
        $this->expectException(RecordNotFoundException::class);
        $this->service->sendReply(999, '本文');
    }

    public function testSendReply_mailFailure_keepsHistoryAndReportsMailError(): void
    {
        $this->failingTransport();

        $result = $this->service->sendReply(1, '返信です');

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('mail', $result['errors']);
        $replies = $this->getTableLocator()->get('TContactReplies');
        $this->assertTrue($replies->exists(['contact_id' => 1, 'body' => '返信です', 'author_type' => 'admin']));
    }

    public function testAddUserReply_notifiesAdminAndRejectsEmptyBody(): void
    {
        $ok = $this->service->addUserReply(1, 1, '追加の質問です');
        $this->assertTrue($ok['success']);
        $this->assertMailCount(1);
        $this->assertMailSentTo('ops@example.com');
        $this->assertMailSubjectContains('お問い合わせに返信がありました：使い方の質問');
        $this->assertMailContainsText('追加の質問です');

        $ng = $this->service->addUserReply(1, 1, '  ');
        $this->assertFalse($ng['success']);
        $this->assertArrayHasKey('body', $ng['errors']);
    }

    public function testAddUserReply_adminMailFailureStillSucceeds(): void
    {
        $this->failingTransport();

        $result = $this->service->addUserReply(1, 1, '追加の質問です');

        $this->assertTrue($result['success']);
    }

    public function testAddUserReplyByToken_notifiesAdmin(): void
    {
        $result = $this->service->addUserReplyByToken('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'メールからの返信', 'ext-mail-1');

        $this->assertTrue($result['success']);
        $this->assertMailCount(1);
        $this->assertMailSentTo('ops@example.com');
        $this->assertMailContainsText('メールからの返信');
    }

    public function testAddUserReplyByToken_emptyBody_returnsValidationErrors(): void
    {
        $result = $this->service->addUserReplyByToken('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', '', 'ext-mail-2');

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('body', $result['errors']);
        $this->assertFalse($this->getTableLocator()->get('TContactReplies')->exists(['external_message_id' => 'ext-mail-2']));
    }

    public function testAddUserReplyByToken_adminMailFailureStillSucceeds(): void
    {
        $this->failingTransport();

        $result = $this->service->addUserReplyByToken('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', '返信', 'ext-mail-3');

        $this->assertTrue($result['success']);
    }
}
