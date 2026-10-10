<?php
declare(strict_types=1);

namespace App\Test\TestCase\Infrastructure\Email;

use App\Infrastructure\Email\ResendWebhookVerifier;
use Cake\TestSuite\TestCase;

class ResendWebhookVerifierTest extends TestCase
{
    private const SECRET = 'whsec_MfKQ9r8GKYqrTwjUPD8ILPZIo2LaLaSw';
    private const ID = 'msg_p5jXN8AQM9LWM0D4loKWxJek';
    private const TIMESTAMP = '1700000000';
    private const BODY = '{"type":"email.received","data":{"email_id":"abc123"}}';

    private function sign(string $secret, string $id, string $timestamp, string $body): string
    {
        $prefixPos = strpos($secret, '_');
        $encoded = $prefixPos === false ? $secret : substr($secret, $prefixPos + 1);
        $secretBytes = base64_decode($encoded, true);
        $signedContent = $id . '.' . $timestamp . '.' . $body;

        return base64_encode(hash_hmac('sha256', $signedContent, (string)$secretBytes, true));
    }

    public function testVerify_validSignature_returnsTrue(): void
    {
        $sig = $this->sign(self::SECRET, self::ID, self::TIMESTAMP, self::BODY);

        $this->assertTrue(ResendWebhookVerifier::verify(
            self::SECRET,
            self::ID,
            self::TIMESTAMP,
            self::BODY,
            'v1,' . $sig,
            (int)self::TIMESTAMP
        ));
    }

    public function testVerify_multipleSignatures_acceptsAnyMatch(): void
    {
        $validSig = $this->sign(self::SECRET, self::ID, self::TIMESTAMP, self::BODY);
        $header = 'v1,invalidsignature== v1,' . $validSig;

        $this->assertTrue(ResendWebhookVerifier::verify(
            self::SECRET,
            self::ID,
            self::TIMESTAMP,
            self::BODY,
            $header,
            (int)self::TIMESTAMP
        ));
    }

    public function testVerify_tamperedBody_returnsFalse(): void
    {
        $sig = $this->sign(self::SECRET, self::ID, self::TIMESTAMP, self::BODY);

        $this->assertFalse(ResendWebhookVerifier::verify(
            self::SECRET,
            self::ID,
            self::TIMESTAMP,
            self::BODY . 'tampered',
            'v1,' . $sig,
            (int)self::TIMESTAMP
        ));
    }

    public function testVerify_wrongSecret_returnsFalse(): void
    {
        $sig = $this->sign('whsec_' . base64_encode('different-secret-bytes'), self::ID, self::TIMESTAMP, self::BODY);

        $this->assertFalse(ResendWebhookVerifier::verify(
            self::SECRET,
            self::ID,
            self::TIMESTAMP,
            self::BODY,
            'v1,' . $sig,
            (int)self::TIMESTAMP
        ));
    }

    public function testVerify_expiredTimestamp_returnsFalse(): void
    {
        $sig = $this->sign(self::SECRET, self::ID, self::TIMESTAMP, self::BODY);
        $farFuture = (int)self::TIMESTAMP + 3600;

        $this->assertFalse(ResendWebhookVerifier::verify(
            self::SECRET,
            self::ID,
            self::TIMESTAMP,
            self::BODY,
            'v1,' . $sig,
            $farFuture
        ));
    }

    public function testVerify_emptySignatureHeader_returnsFalse(): void
    {
        $this->assertFalse(ResendWebhookVerifier::verify(
            self::SECRET,
            self::ID,
            self::TIMESTAMP,
            self::BODY,
            '',
            (int)self::TIMESTAMP
        ));
    }

    public function testVerify_nonNumericTimestamp_returnsFalse(): void
    {
        $sig = $this->sign(self::SECRET, self::ID, 'not-a-number', self::BODY);

        $this->assertFalse(ResendWebhookVerifier::verify(
            self::SECRET,
            self::ID,
            'not-a-number',
            self::BODY,
            'v1,' . $sig,
            (int)self::TIMESTAMP
        ));
    }
}
