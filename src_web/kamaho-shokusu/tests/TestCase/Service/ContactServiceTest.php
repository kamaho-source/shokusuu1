<?php
declare(strict_types=1);

namespace App\Test\TestCase\Service;

use App\Service\ContactService;
use Cake\Datasource\Exception\RecordNotFoundException;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\TestCase;

/**
 * ContactService テスト
 *
 * - shouldSkipAdminNotification: 管理者通知メールのスキップ判定（宛先未設定時は送らない）
 * - addUserReply / getMyList / getDetailForUser: 問い合わせ者本人が自分の問い合わせへ
 *   追記返信できること、他人の問い合わせへはアクセスできないことを検証する。
 */
class ContactServiceTest extends TestCase
{
    protected array $fixtures = [
        'app.TContacts',
        'app.TContactReplies',
    ];

    private ContactService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new ContactService();
    }

    public function testShouldSkip_whenDefaultPlaceholder(): void
    {
        // デフォルト値(admin@localhost)のまま＝未設定扱い→スキップ
        $this->assertTrue(ContactService::shouldSkipAdminNotification('admin@localhost'));
    }

    public function testShouldSkip_whenEmpty(): void
    {
        $this->assertTrue(ContactService::shouldSkipAdminNotification(''));
        $this->assertTrue(ContactService::shouldSkipAdminNotification('   '));
    }

    public function testShouldNotSkip_whenValidAddress(): void
    {
        // 実際の宛先が設定されていれば送信する
        $this->assertFalse(ContactService::shouldSkipAdminNotification('admin@example.com'));
        $this->assertFalse(ContactService::shouldSkipAdminNotification('ops@kamaho-shokusu.jp'));
    }

    // ----------------------------------------------------------------
    // addUserReply / getMyList / getDetailForUser
    // ----------------------------------------------------------------

    public function testAddUserReply_savesAsUserAuthorType(): void
    {
        $result = $this->service->addUserReply(1, 1, '追加で確認したいことがあります。');

        $this->assertTrue($result['success'], json_encode($result['errors']));

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
        $this->expectException(RecordNotFoundException::class);

        $this->service->addUserReply(1, 2, '他人の問い合わせに返信しようとする。');
    }

    public function testGetMyList_scopesToOwner(): void
    {
        $mine = $this->service->getMyList(1);
        $this->assertCount(1, $mine);
        $this->assertSame(1, $mine[0]->id);

        $others = $this->service->getMyList(2);
        $this->assertCount(1, $others);
        $this->assertSame(2, $others[0]->id);

        $none = $this->service->getMyList(999);
        $this->assertCount(0, $none);
    }

    public function testGetDetailForUser_returnsRepliesInAscOrder(): void
    {
        $contact = $this->service->getDetailForUser(1, 1);

        $this->assertSame(1, $contact->id);
        $this->assertCount(1, $contact->t_contact_replies);
        $this->assertSame('admin', $contact->t_contact_replies[0]->author_type);
    }

    public function testSendReply_setsAdminAuthorType(): void
    {
        // メール送信の成否は問わない（通知失敗時も返信履歴は保存される既存仕様）。
        $this->service->sendReply(2, '確認いたします。');

        $repliesTable = TableRegistry::getTableLocator()->get('TContactReplies');
        $latest = $repliesTable->find()
            ->where(['contact_id' => 2])
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($latest);
        $this->assertSame('admin', $latest->author_type);
    }
}
