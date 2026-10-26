<?php
declare(strict_types=1);

namespace App\Test\TestCase\Infrastructure\Email;

use App\Infrastructure\Email\ResendInboundClient;
use Cake\Http\Client;
use Cake\Http\Client\Response;
use Cake\TestSuite\TestCase;

/**
 * ResendInboundClient テスト（HTTP は Client::addMockResponse で差し替え、実通信しない）
 */
class ResendInboundClientTest extends TestCase
{
    protected function tearDown(): void
    {
        Client::clearMockResponses();
        parent::tearDown();
    }

    private function mock(int $status, string $body): void
    {
        Client::addMockResponse(
            'GET',
            'https://api.resend.com/emails/receiving/*',
            new Response(["HTTP/1.1 {$status} X", 'Content-Type: application/json'], $body)
        );
    }

    public function testFetchPlainTextBody_prefersText(): void
    {
        $this->mock(200, (string)json_encode(['text' => '本文', 'html' => '<p>html</p>']));

        $this->assertSame('本文', (new ResendInboundClient('k'))->fetchPlainTextBody('id1'));
    }

    public function testFetchPlainTextBody_fallsBackToStrippedHtml(): void
    {
        $this->mock(200, (string)json_encode(['text' => '  ', 'html' => ' <p>こんにちは<b>世界</b></p> ']));

        $this->assertSame('こんにちは世界', (new ResendInboundClient('k'))->fetchPlainTextBody('id1'));
    }

    public function testFetchPlainTextBody_noBody_throws(): void
    {
        $this->mock(200, (string)json_encode(['text' => null, 'html' => '']));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no text or html body (id=id1)');
        (new ResendInboundClient('k'))->fetchPlainTextBody('id1');
    }

    public function testFetchPlainTextBody_httpError_throws(): void
    {
        $this->mock(404, '{}');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('status=404');
        (new ResendInboundClient('k'))->fetchPlainTextBody('id1');
    }

    public function testFetchPlainTextBody_nonArrayJson_throws(): void
    {
        $this->mock(200, '"just a string"');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not valid JSON');
        (new ResendInboundClient('k'))->fetchPlainTextBody('id1');
    }
}
