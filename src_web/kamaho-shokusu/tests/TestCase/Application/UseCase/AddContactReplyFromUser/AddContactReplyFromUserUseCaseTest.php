<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\AddContactReplyFromUser;

use App\Application\UseCase\AddContactReplyFromUser\AddContactReplyFromUserInput;
use App\Application\UseCase\AddContactReplyFromUser\AddContactReplyFromUserUseCase;
use App\Infrastructure\Notification\CakeContactNotifier;
use App\Infrastructure\Repository\CakeContactRepository;
use Cake\Core\Configure;
use Cake\Mailer\AbstractTransport;
use Cake\Mailer\Message;
use Cake\Mailer\TransportFactory;
use Cake\TestSuite\EmailTrait;
use Cake\TestSuite\TestCase;

/**
 * 問い合わせた本人の画面からの返信（旧 ContactUseCaseTest・ContactMailTest の addUserReply を引き継ぐ）。
 * メールは EmailTrait のデバッグトランスポートで受け、実際の通信は行わない。
 */
class AddContactReplyFromUserUseCaseTest extends TestCase
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

    private function reply(int $contactId, int $userId, string $body): bool
    {
        return (new AddContactReplyFromUserUseCase(new CakeContactRepository(), new CakeContactNotifier()))
            ->execute(new AddContactReplyFromUserInput($contactId, $userId, $body))
            ->saved;
    }

    public function testAddUserReply_savesAsUserAuthorType(): void
    {
        $this->assertTrue($this->reply(1, 1, '追加で確認したいことがあります。'));

        $latest = $this->getTableLocator()->get('TContactReplies')->find()
            ->where(['contact_id' => 1])
            ->orderByDesc('id')
            ->first();

        $this->assertSame('user', $latest->author_type);
        $this->assertSame('追加で確認したいことがあります。', $latest->body);
    }

    public function testAddUserReply_wrongOwner_throwsRecordNotFound(): void
    {
        // contact_id=1 は user_id=1 の問い合わせ。user_id=2 が返信しようとすると拒否される。
        $this->assertFalse($this->reply(1, 2, '他人の問い合わせに返信しようとする。'));
        $this->assertFalse($this->getTableLocator()->get('TContactReplies')->exists(['body' => '他人の問い合わせに返信しようとする。']));
    }

    public function testAddUserReply_notifiesAdminAndRejectsEmptyBody(): void
    {
        $this->assertTrue($this->reply(1, 1, '追加の質問です'));
        $this->assertMailCount(1);
        $this->assertMailSentTo('ops@example.com');
        $this->assertMailSubjectContains('お問い合わせに返信がありました：使い方の質問');
        $this->assertMailContainsText('追加の質問です');

        $this->assertFalse($this->reply(1, 1, '  '));
    }

    public function testAddUserReply_adminMailFailureStillSucceeds(): void
    {
        TransportFactory::drop('default');
        TransportFactory::setConfig('default', new class (['className' => 'Debug']) extends AbstractTransport {
            public function send(Message $message): array
            {
                throw new \RuntimeException('smtp down');
            }
        });

        $this->assertTrue($this->reply(1, 1, '追加の質問です'));
    }
}
