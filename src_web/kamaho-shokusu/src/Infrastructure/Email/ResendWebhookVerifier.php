<?php
declare(strict_types=1);

namespace App\Infrastructure\Email;

/**
 * Resend（Svix）のWebhook署名を検証する。
 *
 * @see https://resend.com/docs/dashboard/webhooks/verify-webhooks-requests Svix署名検証仕様
 */
final class ResendWebhookVerifier
{
    /** リプレイ攻撃対策：タイムスタンプの許容誤差（秒） */
    private const TOLERANCE_SECONDS = 300;

    /**
     * @param string $secret Resendダッシュボードで発行される Webhook 署名シークレット（whsec_ プレフィックス形式）
     * @param string $svixId `svix-id` ヘッダの値
     * @param string $svixTimestamp `svix-timestamp` ヘッダの値
     * @param string $rawBody リクエストの生ボディ（パース前）
     * @param string $svixSignature `svix-signature` ヘッダの値（スペース区切りで複数の `v1,<署名>` を含みうる）
     * @param int|null $now テスト用に現在時刻を注入する場合に指定する
     */
    public static function verify(
        string $secret,
        string $svixId,
        string $svixTimestamp,
        string $rawBody,
        string $svixSignature,
        ?int $now = null
    ): bool {
        if ($secret === '' || $svixId === '' || $svixTimestamp === '' || $svixSignature === '') {
            return false;
        }

        if (!self::isTimestampFresh($svixTimestamp, $now ?? time())) {
            return false;
        }

        $secretBytes = self::decodeSecret($secret);
        if ($secretBytes === null) {
            return false;
        }

        $signedContent = $svixId . '.' . $svixTimestamp . '.' . $rawBody;
        $expected = base64_encode(hash_hmac('sha256', $signedContent, $secretBytes, true));

        foreach (explode(' ', trim($svixSignature)) as $candidate) {
            $parts = explode(',', $candidate, 2);
            if (count($parts) !== 2) {
                continue;
            }

            if (hash_equals($expected, $parts[1])) {
                return true;
            }
        }

        return false;
    }

    private static function isTimestampFresh(string $svixTimestamp, int $now): bool
    {
        if (!ctype_digit($svixTimestamp)) {
            return false;
        }

        return abs($now - (int)$svixTimestamp) <= self::TOLERANCE_SECONDS;
    }

    private static function decodeSecret(string $secret): ?string
    {
        $prefixPos = strpos($secret, '_');
        $encoded = $prefixPos === false ? $secret : substr($secret, $prefixPos + 1);
        $decoded = base64_decode($encoded, true);

        return $decoded === false ? null : $decoded;
    }

    private function __construct()
    {
    }
}
