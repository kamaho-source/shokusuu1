<?php
declare(strict_types=1);

namespace App\Service\Stripe;

/**
 * Stripe API 操作のインターフェース。
 *
 * 本番実装は STRIPE_SECRET_KEY を使用する。
 * 開発・テスト環境では NullStripeService を使用する。
 */
interface StripeServiceInterface
{
    /**
     * Stripe にカスタマーを作成して Customer ID (cus_xxx) を返す。
     *
     * @return string|null 作成成功時は stripe_customer_id, 失敗時は null
     */
    public function createCustomer(string $tenantName, string $email): ?string;

    /**
     * Stripe Invoice を作成して Invoice ID (in_xxx) を返す。
     *
     * @param string $customerId  stripe_customer_id
     * @param int    $amountYen   請求金額（税抜, 円）
     * @param string $description 請求内容説明
     * @return string|null 作成成功時は stripe_invoice_id
     */
    public function createInvoice(string $customerId, int $amountYen, string $description): ?string;

    /**
     * Stripe Webhook シグネチャを検証して受信イベントを返す。
     *
     * @throws \RuntimeException 検証失敗時
     */
    public function constructWebhookEvent(string $payload, string $sigHeader, string $secret): array;
}
