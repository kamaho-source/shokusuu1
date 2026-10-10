<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Infrastructure\Email\ResendWebhookVerifier;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * WebhooksController::resendInbound の統合テスト。
 *
 * 実際のResend APIへのネットワーク呼び出しが発生する「本文取得→スレッド登録」までの
 * 真のハッピーパスは Service層（ContactServiceTest::addUserReplyByToken）で
 * ネットワーク非依存に検証済み。ここではWebhookエンドポイント自体の
 * 署名検証・対象外イベントの扱いなど、ネットワーク呼び出しに到達しない範囲を検証する。
 */
class WebhooksControllerTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = [
        'app.TContacts',
        'app.TContactReplies',
    ];

    private const SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';

    protected function setUp(): void
    {
        parent::setUp();
        putenv('RESEND_WEBHOOK_SECRET=' . self::SECRET);
    }

    protected function tearDown(): void
    {
        putenv('RESEND_WEBHOOK_SECRET');
        parent::tearDown();
    }

    private function sign(string $id, string $timestamp, string $body): string
    {
        $prefixPos = strpos(self::SECRET, '_');
        $encoded = substr(self::SECRET, $prefixPos + 1);
        $secretBytes = (string)base64_decode($encoded, true);
        $signedContent = $id . '.' . $timestamp . '.' . $body;

        return base64_encode(hash_hmac('sha256', $signedContent, $secretBytes, true));
    }

    private function postSigned(string $body, ?string $signatureOverride = null): void
    {
        $id = 'msg_test_001';
        $timestamp = (string)time();
        $signature = $signatureOverride ?? ('v1,' . $this->sign($id, $timestamp, $body));

        $this->configRequest([
            'headers' => [
                'Content-Type'    => 'application/json',
                'svix-id'         => $id,
                'svix-timestamp'  => $timestamp,
                'svix-signature'  => $signature,
            ],
        ]);

        $this->post('/webhooks/resend/inbound', $body);
    }

    public function testResendInbound_invalidSignature_returns401(): void
    {
        $body = json_encode(['type' => 'email.received', 'data' => ['email_id' => 'x']]);

        $this->postSigned((string)$body, 'v1,明らかに不正な署名');

        $this->assertResponseCode(401);
    }

    public function testResendInbound_noSignatureHeaders_returns401(): void
    {
        $this->post('/webhooks/resend/inbound', '{}');

        $this->assertResponseCode(401);
    }

    public function testResendInbound_wrongEventType_returns200WithoutProcessing(): void
    {
        $body = json_encode(['type' => 'email.delivered', 'data' => []]);

        $this->postSigned((string)$body);

        $this->assertResponseOk();
    }

    public function testResendInbound_unrecognizedRecipient_returns200WithoutProcessing(): void
    {
        $body = json_encode([
            'type' => 'email.received',
            'data' => [
                'email_id' => 'resend-id-xyz',
                'to'       => ['someone-else@kamaho-shokusu.jp'],
            ],
        ]);

        $this->postSigned((string)$body);

        $this->assertResponseOk();

        // 処理対象外のため、返信は登録されていないこと。
        $repliesTable = TableRegistry::getTableLocator()->get('TContactReplies');
        $count = $repliesTable->find()->where(['external_message_id' => 'resend-id-xyz'])->count();
        $this->assertSame(0, $count);
    }
}
