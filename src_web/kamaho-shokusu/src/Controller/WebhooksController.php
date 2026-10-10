<?php
declare(strict_types=1);

namespace App\Controller;

use App\Domain\ValueObject\EmailQuoteStripper;
use App\Infrastructure\Email\ResendInboundClient;
use App\Infrastructure\Email\ResendWebhookVerifier;
use App\Service\ContactService;
use Cake\Event\EventInterface;
use Cake\Http\Response;
use Cake\Log\Log;
use Throwable;

/**
 * 外部サービスからのWebhookを受け付けるコントローラー。
 *
 * ログインセッションを持たない外部サービスからの呼び出しのため、
 * 認証・認可（Authentication/Authorization）は行わず、Webhook自体の署名検証を
 * 認可の代替とする。CSRF保護も Application::middleware() 側で個別に除外している。
 */
class WebhooksController extends AppController
{
    private ContactService $contactService;

    public function initialize(): void
    {
        parent::initialize();
        $this->contactService = new ContactService();
    }

    public function beforeFilter(EventInterface $event): void
    {
        parent::beforeFilter($event);
        $this->Authentication->allowUnauthenticated(['resendInbound']);
        // 外部サービスからの生JSON POSTのため、HTMLフォーム由来の改ざん防止トークンは対象外とする。
        // 保護はWebhook署名検証（Svix HMAC）で行う。
        $this->FormProtection->setConfig('unlockedActions', ['resendInbound']);
    }

    /**
     * Resend Inbound（email.received）Webhookを受け取り、本人からの返信として登録する。
     */
    public function resendInbound(): Response
    {
        $this->Authorization->skipAuthorization();
        $this->request->allowMethod(['post']);

        $rawBody = (string)$this->request->getBody();

        $secret = (string)env('RESEND_WEBHOOK_SECRET', '');
        $isValid = ResendWebhookVerifier::verify(
            $secret,
            $this->request->getHeaderLine('svix-id'),
            $this->request->getHeaderLine('svix-timestamp'),
            $rawBody,
            $this->request->getHeaderLine('svix-signature')
        );

        if (!$isValid) {
            Log::warning('Resend inbound webhook: invalid signature');

            return $this->response->withStatus(401);
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload) || ($payload['type'] ?? null) !== 'email.received') {
            // 対象外イベントは 200 で即座にACKし、Resend側の不要な再送を防ぐ。
            return $this->response->withType('application/json')->withStringBody('{"ok":true}');
        }

        $data = $payload['data'] ?? [];
        $emailId = (string)($data['email_id'] ?? '');
        $recipients = $data['to'] ?? [];

        $token = $this->extractReplyToken(is_array($recipients) ? $recipients : [$recipients]);

        if ($emailId === '' || $token === null) {
            Log::warning('Resend inbound webhook: missing email_id or unrecognized recipient token');

            return $this->response->withType('application/json')->withStringBody('{"ok":true}');
        }

        try {
            $client = new ResendInboundClient((string)env('RESEND_API_KEY', ''));
            $rawText = $client->fetchPlainTextBody($emailId);
        } catch (Throwable $e) {
            Log::error('Resend inbound webhook: failed to fetch email body - ' . $e->getMessage());

            // 本文取得に失敗した場合は 500 を返し、Resend側の再送に任せる。
            return $this->response->withStatus(500);
        }

        $replyBody = EmailQuoteStripper::strip($rawText);
        if ($replyBody === '') {
            Log::info('Resend inbound webhook: stripped body was empty, skipping (email_id=' . $emailId . ')');

            return $this->response->withType('application/json')->withStringBody('{"ok":true}');
        }

        $result = $this->contactService->addUserReplyByToken($token, $replyBody, $emailId);

        if (!$result['success']) {
            Log::warning('Resend inbound webhook: addUserReplyByToken failed - ' . json_encode($result['errors']));
        }

        return $this->response->withType('application/json')->withStringBody('{"ok":true}');
    }

    /**
     * 宛先アドレスの配列から `reply+{token}@...` 形式のトークンを抽出する。
     *
     * @param array<mixed> $recipients
     */
    private function extractReplyToken(array $recipients): ?string
    {
        foreach ($recipients as $recipient) {
            $address = is_array($recipient) ? (string)($recipient['email'] ?? '') : (string)$recipient;
            if (preg_match('/^reply\+([a-f0-9]{32})@/i', $address, $matches) === 1) {
                return $matches[1];
            }
        }

        return null;
    }
}
