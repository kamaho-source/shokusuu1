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
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * 問い合わせの UseCase・Query・通知の判定。旧 ContactServiceTest を引き継ぐ
 *
 * - shouldSkipAdminNotification: 管理者通知メールのスキップ判定（宛先未設定時は送らない）
 * - getMyList / getDetailForUser: 問い合わせ者本人が自分の問い合わせを
 *   見られること、他人の問い合わせは見られないことを検証する。
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
    // getMyList / getDetailForUser（本人の返信は AddContactReplyFromUser / FromEmail のテストへ移した）
    // ----------------------------------------------------------------

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
        (new ReplyToContactUseCase(new CakeContactRepository(), new CakeContactNotifier()))->execute(new \App\Application\UseCase\ReplyToContact\ReplyToContactInput(2, '確認いたします。'));

        $repliesTable = TableRegistry::getTableLocator()->get('TContactReplies');
        $latest = $repliesTable->find()
            ->where(['contact_id' => 2])
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($latest);
        $this->assertSame('admin', $latest->author_type);
    }
}
