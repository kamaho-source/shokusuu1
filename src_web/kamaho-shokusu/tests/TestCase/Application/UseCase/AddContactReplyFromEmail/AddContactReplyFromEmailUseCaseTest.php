<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\AddContactReplyFromEmail;

use App\Application\UseCase\AddContactReplyFromEmail\AddContactReplyFromEmailInput;
use App\Application\UseCase\AddContactReplyFromEmail\AddContactReplyFromEmailOutput;
use App\Application\UseCase\AddContactReplyFromEmail\AddContactReplyFromEmailUseCase;
use App\Infrastructure\Notification\CakeContactNotifier;
use App\Infrastructure\Repository\CakeContactRepository;
use Cake\Core\Configure;
use Cake\Mailer\AbstractTransport;
use Cake\Mailer\Message;
use Cake\Mailer\TransportFactory;
use Cake\TestSuite\EmailTrait;
use Cake\TestSuite\TestCase;

/**
 * 返信メール（受信 Webhook）からの本人の返信（旧 ContactUseCaseTest・ContactMailTest の addUserReplyByToken を引き継ぐ）。
 * メールは EmailTrait のデバッグトランスポートで受け、実際の通信は行わない。
 */
class AddContactReplyFromEmailUseCaseTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        Configure::delete('App.adminEmail');
        parent::tearDown();
    }

    private function reply(string $token, string $body, string $externalMessageId): AddContactReplyFromEmailOutput
    {
        return (new AddContactReplyFromEmailUseCase(new CakeContactRepository(), new CakeContactNotifier()))
            ->execute(new AddContactReplyFromEmailInput($token, $body, $externalMessageId));
    }

    public function testAddUserReplyByToken_savesAsUserAuthorType(): void
    {
        $result = $this->reply('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'メールから返信しました。', 'resend-email-id-001');

        $this->assertSame(AddContactReplyFromEmailOutput::SAVED, $result->status, json_encode($result->errors));

        $latest = $this->getTableLocator()->get('TContactReplies')->find()
            ->where(['contact_id' => 1])
            ->orderByDesc('id')
            ->first();

        $this->assertSame('user', $latest->author_type);
        $this->assertSame('メールから返信しました。', $latest->body);
        $this->assertSame('resend-email-id-001', $latest->external_message_id);
    }

    public function testAddUserReplyByToken_unknownToken_returnsFailureWithoutThrowing(): void
    {
        $result = $this->reply('not-a-real-token', '本文', 'resend-email-id-002');

        $this->assertSame(AddContactReplyFromEmailOutput::UNKNOWN_TOKEN, $result->status);
        $this->assertFalse($result->succeeded());
        $this->assertArrayHasKey('token', $result->errors);
    }

    public function testAddUserReplyByToken_duplicateExternalMessageId_isIdempotent(): void
    {
        $first = $this->reply('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', '1回目の返信。', 'resend-email-id-003');
        $this->assertSame(AddContactReplyFromEmailOutput::SAVED, $first->status);

        // Webhookの再送を模倣：同じ external_message_id で再度呼び出す。
        $second = $this->reply('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', '1回目の返信。', 'resend-email-id-003');
        $this->assertSame(AddContactReplyFromEmailOutput::DUPLICATE, $second->status);
        $this->assertTrue($second->succeeded());

        $count = $this->getTableLocator()->get('TContactReplies')->find()
            ->where(['external_message_id' => 'resend-email-id-003'])
            ->count();

        $this->assertSame(1, $count);
    }

    public function testAddUserReplyByToken_notifiesAdmin(): void
    {
        $result = $this->reply('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', 'メールからの返信', 'ext-mail-1');

        $this->assertSame(AddContactReplyFromEmailOutput::SAVED, $result->status);
        $this->assertMailCount(1);
        $this->assertMailSentTo('ops@example.com');
        $this->assertMailContainsText('メールからの返信');
    }

    public function testAddUserReplyByToken_emptyBody_returnsValidationErrors(): void
    {
        $result = $this->reply('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', '', 'ext-mail-2');

        $this->assertSame(AddContactReplyFromEmailOutput::INVALID, $result->status);
        $this->assertFalse($result->succeeded());
        $this->assertArrayHasKey('body', $result->errors);
        $this->assertFalse($this->getTableLocator()->get('TContactReplies')->exists(['external_message_id' => 'ext-mail-2']));
    }

    public function testAddUserReplyByToken_adminMailFailureStillSucceeds(): void
    {
        TransportFactory::drop('default');
        TransportFactory::setConfig('default', new class (['className' => 'Debug']) extends AbstractTransport {
            public function send(Message $message): array
            {
                throw new \RuntimeException('smtp down');
            }
        });

        $result = $this->reply('bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb', '返信', 'ext-mail-3');

        $this->assertSame(AddContactReplyFromEmailOutput::SAVED, $result->status);
    }
}
