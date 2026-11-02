<?php
declare(strict_types=1);

namespace App\Test\TestCase\Application\UseCase\Contact;

use App\Application\UseCase\AddContactReply\AddContactReplyUseCase;
use App\Application\UseCase\ReplyToContact\ReplyToContactUseCase;
use App\Application\UseCase\SubmitContact\SubmitContactOutput;
use App\Application\UseCase\SubmitContact\SubmitContactUseCase;
use App\Domain\Exception\NotFoundException;
use App\Infrastructure\Notification\CakeContactNotifier;
use App\Infrastructure\Query\CakeContactQuery;
use App\Infrastructure\Repository\CakeContactRepository;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * 問い合わせの UseCase・Query・通知の判定。旧 ContactServiceTest を引き継ぐ
 *
 * - shouldSkipAdminNotification: 管理者通知メールのスキップ判定（宛先未設定時は送らない）
 * - addUserReply / getMyList / getDetailForUser: 問い合わせ者本人が自分の問い合わせへ
 *   追記返信できること、他人の問い合わせへはアクセスできないことを検証する。
 */
class ContactUseCaseTest extends TestCase
{
    protected array $fixtures = [
        'app.TContacts',
        'app.TContactReplies',
    ];

    protected function setUp(): void
    {
        parent::setUp();
    }

    private function replies(): AddContactReplyUseCase
    {
        return new AddContactReplyUseCase(new CakeContactRepository(), new CakeContactNotifier());
    }

    public function testShouldSkip_whenDefaultPlaceholder(): void
    {
        // デフォルト値(admin@localhost)のまま＝未設定扱い→スキップ
        $this->assertTrue(CakeContactNotifier::shouldSkipAdminNotification('admin@localhost'));
    }

    public function testShouldSkip_whenEmpty(): void
    {
        $this->assertTrue(CakeContactNotifier::shouldSkipAdminNotification(''));
        $this->assertTrue(CakeContactNotifier::shouldSkipAdminNotification('   '));
    }

    public function testShouldNotSkip_whenValidAddress(): void
    {
        // 実際の宛先が設定されていれば送信する
        $this->assertFalse(CakeContactNotifier::shouldSkipAdminNotification('admin@example.com'));
        $this->assertFalse(CakeContactNotifier::shouldSkipAdminNotification('ops@kamaho-shokusu.jp'));
    }

    // ----------------------------------------------------------------
    // addUserReply / getMyList / getDetailForUser
    // ----------------------------------------------------------------

    public function testAddUserReply_savesAsUserAuthorType(): void
    {
        $this->assertTrue($this->replies()->fromUser(1, 1, '追加で確認したいことがあります。'));

        $repliesTable = TableRegistry::getTableLocator()->get('TContactReplies');
        $latest = $repliesTable->find()
            ->where(['contact_id' => 1])
            ->orderByDesc('id')
            ->first();

        $this->assertSame('user', $latest->author_type);
        $this->assertSame('追加で確認したいことがあります。', $latest->body);
    }

    public function testAddUserReply_wrongOwner_throwsRecordNotFound(): void
    {
        // contact_id=1 は user_id=1 の問い合わせ。user_id=2 が返信しようとすると拒否される。
        $this->assertFalse($this->replies()->fromUser(1, 2, '他人の問い合わせに返信しようとする。'));
        $this->assertFalse(TableRegistry::getTableLocator()->get('TContactReplies')->exists(['body' => '他人の問い合わせに返信しようとする。']));
    }

    public function testGetMyList_scopesToOwner(): void
    {
        $mine = (new CakeContactQuery())->mine(1);
        $this->assertCount(1, $mine);
        $this->assertSame(1, $mine[0]->id);

        $others = (new CakeContactQuery())->mine(2);
        $this->assertCount(1, $others);
        $this->assertSame(2, $others[0]->id);

        $none = (new CakeContactQuery())->mine(999);
        $this->assertCount(0, $none);
    }

    public function testGetDetailForUser_returnsRepliesInAscOrder(): void
    {
        $contact = (new CakeContactRepository())->findForUser(1, 1);

        $this->assertSame(1, $contact?->id);
        $this->assertCount(1, $contact->replies);
        $this->assertSame('admin', $contact->replies[0]->authorType);
        $this->assertNull((new CakeContactRepository())->findForUser(1, 2), '他人の問い合わせは見つからない');
    }

    public function testSendReply_setsAdminAuthorType(): void
    {
        // メール送信の成否は問わない（通知失敗時も返信履歴は保存される既存仕様）。
        (new ReplyToContactUseCase(new CakeContactRepository(), new CakeContactNotifier()))->execute(2, '確認いたします。');

        $repliesTable = TableRegistry::getTableLocator()->get('TContactReplies');
        $latest = $repliesTable->find()
            ->where(['contact_id' => 2])
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($latest);
        $this->assertSame('admin', $latest->author_type);
    }

    // ----------------------------------------------------------------
    // addUserReplyByToken（受信メール経由の本人返信）
    // ----------------------------------------------------------------

    public function testAddUserReplyByToken_savesAsUserAuthorType(): void
    {
        $result = $this->replies()->fromEmail(
            'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            'メールから返信しました。',
            'resend-email-id-001'
        );

        $this->assertTrue($result['success'], json_encode($result['errors']));

        $repliesTable = TableRegistry::getTableLocator()->get('TContactReplies');
        $latest = $repliesTable->find()
            ->where(['contact_id' => 1])
            ->orderByDesc('id')
            ->first();

        $this->assertSame('user', $latest->author_type);
        $this->assertSame('メールから返信しました。', $latest->body);
        $this->assertSame('resend-email-id-001', $latest->external_message_id);
    }

    public function testAddUserReplyByToken_unknownToken_returnsFailureWithoutThrowing(): void
    {
        $result = $this->replies()->fromEmail('not-a-real-token', '本文', 'resend-email-id-002');

        $this->assertFalse($result['success']);
        $this->assertArrayHasKey('token', $result['errors']);
    }

    public function testAddUserReplyByToken_duplicateExternalMessageId_isIdempotent(): void
    {
        $first = $this->replies()->fromEmail(
            'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            '1回目の返信。',
            'resend-email-id-003'
        );
        $this->assertTrue($first['success']);

        // Webhookの再送を模倣：同じ external_message_id で再度呼び出す。
        $second = $this->replies()->fromEmail(
            'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
            '1回目の返信。',
            'resend-email-id-003'
        );
        $this->assertTrue($second['success']);

        $repliesTable = TableRegistry::getTableLocator()->get('TContactReplies');
        $count = $repliesTable->find()
            ->where(['external_message_id' => 'resend-email-id-003'])
            ->count();

        $this->assertSame(1, $count);
    }
}
