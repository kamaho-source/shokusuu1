<?php
declare(strict_types=1);

namespace App\Infrastructure\Email;

use Cake\Http\Client;
use RuntimeException;

/**
 * Resend Inbound（受信メール）APIクライアント。
 *
 * Webhook（email.received）のペイロードには本文(text/html)が含まれないため、
 * 本文取得には別途このAPIを叩く必要がある。
 *
 * @see https://resend.com/docs/api-reference/emails/retrieve-received-email
 */
final class ResendInboundClient
{
    private const BASE_URL = 'https://api.resend.com';

    /**
     * @param string $apiKey Resend APIキー（RESEND_API_KEY）
     */
    public function __construct(private readonly string $apiKey)
    {
    }

    /**
     * 受信メールの本文（プレーンテキスト優先、無ければHTMLタグを除去した簡易テキスト）を取得する。
     *
     * @throws \RuntimeException 取得または解析に失敗した場合
     */
    public function fetchPlainTextBody(string $emailId): string
    {
        $http = new Client(['timeout' => 10]);
        $response = $http->get(
            self::BASE_URL . '/emails/receiving/' . rawurlencode($emailId),
            [],
            ['headers' => ['Authorization' => 'Bearer ' . $this->apiKey]]
        );

        if (!$response->isOk()) {
            throw new RuntimeException(sprintf(
                'Resend inbound email fetch failed (id=%s, status=%d)',
                $emailId,
                $response->getStatusCode()
            ));
        }

        $data = $response->getJson();
        if (!is_array($data)) {
            throw new RuntimeException(sprintf('Resend inbound email response was not valid JSON (id=%s)', $emailId));
        }

        $text = $data['text'] ?? null;
        if (is_string($text) && trim($text) !== '') {
            return $text;
        }

        $html = $data['html'] ?? null;
        if (is_string($html) && trim($html) !== '') {
            return trim(strip_tags($html));
        }

        throw new RuntimeException(sprintf('Resend inbound email had no text or html body (id=%s)', $emailId));
    }
}
