<?php
declare(strict_types=1);

namespace App\Service\Stripe;

use Cake\Log\Log;

/**
 * Stripe 未設定環境用の No-op 実装。
 *
 * STRIPE_SECRET_KEY が未設定の場合にバインドされる。
 * 本番では StripeService を使用すること。
 */
final class NullStripeService implements StripeServiceInterface
{
    public function createCustomer(string $tenantName, string $email): ?string
    {
        Log::info("[NullStripe] createCustomer skipped: {$tenantName} <{$email}>");
        return null;
    }

    public function createInvoice(string $customerId, int $amountYen, string $description): ?string
    {
        Log::info("[NullStripe] createInvoice skipped: cus={$customerId}, amount={$amountYen}");
        return null;
    }

    public function constructWebhookEvent(string $payload, string $sigHeader, string $secret): array
    {
        throw new \RuntimeException('Stripe is not configured.');
    }
}
