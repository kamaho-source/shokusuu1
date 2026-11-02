<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\Contact;

use App\Application\UseCase\ReplyToContact\ReplyToContactUseCase;
use App\Application\UseCase\SubmitContact\SubmitContactOutput;
use App\Application\UseCase\SubmitContact\SubmitContactUseCase;
use App\Domain\Exception\NotFoundException;
use App\Infrastructure\Notification\CakeContactNotifier;
use App\Infrastructure\Query\CakeContactQuery;
use App\Infrastructure\Repository\CakeContactRepository;
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
 * 問い合わせの保存・メール送信・Slack通知・一覧取得（SubmitContact・ReplyToContact・CakeContactQuery）。旧 ContactServiceMailTest を引き継ぐ。
 *
 * メールは EmailTrait のデバッグトランスポート、Slack は Http\Client のモックレスポンスで
 * 受け、実際の通信は行わない。
 */
class ContactMailTest extends TestCase
{
    use EmailTrait;

    protected array $fixtures = [
        'app.TContacts',
        'app.TContactReplies',
    ];

    protected function setUp(): void
    {
        parent::setUp();
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

    private function submit(array $data, ?int $userId): SubmitContactOutput
    {
        return (new SubmitContactUseCase(new CakeContactRepository(), new CakeContactNotifier()))->execute($data, $userId);
    }

    private function sendReply(int $contactId, string $body): bool
    {
        return (new ReplyToContactUseCase(new CakeContactRepository(), new CakeContactNotifier()))->execute($contactId, $body);
    }

    private function latestContact(): \App\Infrastructure\Persistence\Entity\TContact
    {
        return $this->getTableLocator()->get('TContacts')->find()->orderByDesc('id')->firstOrFail();
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
        $result = $this->submit(['category' => 'でたらめ', 'name' => '', 'email' => 'x', 'body' => ''], 1);

        $this->assertFalse($result->saved);
        $errors = $result->errors;
        $this->assertArrayHasKey('category', $errors);
        $this->assertArrayHasKey('name', $errors);
        $this->assertArrayHasKey('body', $errors);
        $this->assertNoMailSent();
        $this->assertSame(2, $this->getTableLocator()->get('TContacts')->find()->count());
    }

    public function testSubmit_valid_savesTrimmedWithTokenAndSendsAutoReplyAndAdminMail(): void
    {
        $result = $this->submit($this->validInput(), 7);

        $this->assertTrue($result->saved);
        $saved = $this->latestContact();
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

        $this->submit($this->validInput('ご意見・ご要望'), null);

        $token = (string)$this->latestContact()->reply_token;
        $this->assertMailSentWithAt(0, 'reply+' . $token . '@in.example.test', 'replyTo');
        $this->assertMailSubjectContainsAt(0, 'ご意見・ご要望を受け付けました');
    }

    public function testSubmit_adminEmailUnset_skipsAdminMail(): void
    {
        Configure::write('App.adminEmail', 'admin@localhost');

        $result = $this->submit($this->validInput('使い方の質問'), 1);

        $this->assertTrue($result->saved);
        $this->assertMailCount(1);
        $this->assertMailSentTo('new@example.com');
    }

    public function testSubmit_mailFailureDoesNotFailSubmission(): void
    {
        $this->failingTransport();

        $result = $this->submit($this->validInput(), 1);

        $this->assertTrue($result->saved, 'メール送信に失敗しても問い合わせ自体は保存される');
        $this->assertSame(3, $this->getTableLocator()->get('TContacts')->find()->count());
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

        $result = $this->submit($this->validInput(), 1);

        $this->assertTrue($result->saved);
        $this->assertCount(1, $posted);
        $this->assertStringContainsString('新規 太郎', (string)json_decode($posted[0], true)['text']);
    }

    // ------------------------------------------------------- list / detail

    public function testGetListAndGetDetail(): void
    {
        $query = new CakeContactQuery();
        $list = $query->page(1);
        $this->assertSame([2, 1], array_map(fn($c) => $c->id, $list));
        $this->assertCount(1, $query->page(1, 1));
        $this->assertCount(1, $query->page(2, 1));
        $this->assertSame([], $query->page(3, 1));
        $this->assertSame([2], array_map(fn($c) => $c->id, $query->page(0, 1)), '0 以下は 1 ページ目');

        $this->assertSame(1, $query->find(1)?->id);
        $this->assertNull($query->find(999));
    }

    // --------------------------------------------------------------- replies

    public function testSendReply_sendsMailFromSupportWithReplyTo(): void
    {
        $this->assertTrue($this->sendReply(1, '  手順をご案内します。  '));
        $this->assertMailCount(1);
        $this->assertMailSentTo('taro@example.com');
        $this->assertMailSentFrom('support@kamaho-shokusu.jp');
        $this->assertMailSubjectContains('Re: 使い方の質問について');
        $this->assertMailContainsText('手順をご案内します。');
        $this->assertMailSentWith('reply+aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa@reply.kamaho-shokusu.jp', 'replyTo');
    }

    public function testSendReply_emptyBody_returnsValidationErrorsWithoutMail(): void
    {
        $this->assertFalse($this->sendReply(1, '   '));
        $this->assertNoMailSent();
        $this->assertFalse($this->getTableLocator()->get('TContactReplies')->exists(['contact_id' => 1, 'body' => '']));
    }

    public function testSendReply_unknownContact_throws(): void
    {
        $this->expectException(NotFoundException::class);
        $this->sendReply(999, '本文');
    }

    public function testSendReply_mailFailure_keepsHistoryAndReportsMailError(): void
    {
        $this->failingTransport();

        $this->assertFalse($this->sendReply(1, '返信です'), 'メールが送れなかったことを返す');
        $replies = $this->getTableLocator()->get('TContactReplies');
        $this->assertTrue($replies->exists(['contact_id' => 1, 'body' => '返信です', 'author_type' => 'admin']));
    }
}
