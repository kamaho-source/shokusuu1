<?php
declare(strict_types=1);

namespace App\Test\TestCase\Infrastructure\AI;

use App\Infrastructure\AI\GroqClient;
use Cake\TestSuite\TestCase;

require_once __DIR__ . '/CurlFake.php';

/**
 * GroqClient テスト（curl は CurlFake で差し替え、実通信しない）
 */
class GroqClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        CurlFake::enable();
    }

    protected function tearDown(): void
    {
        CurlFake::disable();
        parent::tearDown();
    }

    private static function sse(string $content): string
    {
        return 'data: ' . json_encode(['choices' => [['delta' => ['content' => $content]]]], JSON_UNESCAPED_UNICODE) . "\n\n";
    }

    /**
     * @return array{0: array{success: bool, httpCode: int, fullResponse: string, userMessage: ?string}, 1: list<string>}
     */
    private function stream(): array
    {
        $received = [];
        $client   = new GroqClient('test-model', 'secret-key');
        $result   = $client->streamChat(
            [['role' => 'user', 'content' => 'hi']],
            function (string $c) use (&$received): void {
                $received[] = $c;
            }
        );

        return [$result, $received];
    }

    public function testStreamChat_success_deliversDeltasAndSendsRequest(): void
    {
        CurlFake::$chunks = [self::sse('こん'), self::sse('にちは'), "data: [DONE]\n\n"];

        [$result, $received] = $this->stream();

        $this->assertTrue($result['success']);
        $this->assertSame(200, $result['httpCode']);
        $this->assertSame('こんにちは', $result['fullResponse']);
        $this->assertNull($result['userMessage']);
        $this->assertSame(['こん', 'にちは'], $received);

        $opts    = CurlFake::$lastOptions;
        $payload = json_decode((string)$opts[CURLOPT_POSTFIELDS], true);
        $this->assertSame('test-model', $payload['model']);
        $this->assertTrue($payload['stream']);
        $this->assertContains('Authorization: Bearer secret-key', $opts[CURLOPT_HTTPHEADER]);
    }

    public function testStreamChat_lineSplitAcrossChunks_isReassembled(): void
    {
        $line = self::sse('分割');
        CurlFake::$chunks = [substr($line, 0, 20), substr($line, 20)];

        [$result, $received] = $this->stream();

        $this->assertSame('分割', $result['fullResponse']);
        $this->assertSame(['分割'], $received);
    }

    public function testStreamChat_ignoresEmptyInvalidAndNonDataLines(): void
    {
        CurlFake::$chunks = [
            ": keep-alive\n\n",
            "data: not-json\n\n",
            self::sse(''),
            "event: x\n",
            self::sse('本文'),
        ];

        [$result, $received] = $this->stream();

        $this->assertSame('本文', $result['fullResponse']);
        $this->assertSame(['本文'], $received);
    }

    public function testStreamChat_stripsSpecialTokensAndControlChars(): void
    {
        CurlFake::$chunks = [
            self::sse('<|channel|>final<|message|>A'),
            // 特殊トークンがデルタ境界で分割される
            self::sse('B<|en'),
            self::sse('d|>C' . "\x07" . "\u{200B}" . "\n" . 'D'),
        ];

        [$result] = $this->stream();

        $this->assertSame("finalABC\nD", $result['fullResponse']);
    }

    public function testStreamChat_unfinishedTokenAtEnd_isDroppedOnFlush(): void
    {
        CurlFake::$chunks = [self::sse('末尾<|unfin')];

        [$result, $received] = $this->stream();

        $this->assertSame('末尾', $result['fullResponse']);
        $this->assertSame(['末尾'], $received);
    }

    public function testStreamChat_trailingLoneLessThan_isDroppedOnFlush(): void
    {
        CurlFake::$chunks = [self::sse('abc<')];

        [$result] = $this->stream();

        $this->assertSame('abc', $result['fullResponse']);
    }

    public function testStreamChat_http429_returnsRateLimitMessageAndNoCallback(): void
    {
        CurlFake::$status = 429;
        CurlFake::$chunks = [json_encode(['error' => ['message' => 'rate limited']])];

        [$result, $received] = $this->stream();

        $this->assertFalse($result['success']);
        $this->assertSame(429, $result['httpCode']);
        $this->assertSame(GroqClient::RATE_LIMIT_MESSAGE, $result['userMessage']);
        $this->assertSame('', $result['fullResponse']);
        $this->assertSame([], $received);
    }

    public function testStreamChat_http500_returnsGenericMessage(): void
    {
        CurlFake::$status = 500;
        CurlFake::$chunks = ['oops'];

        [$result] = $this->stream();

        $this->assertFalse($result['success']);
        $this->assertSame(GroqClient::GENERIC_ERROR_MESSAGE, $result['userMessage']);
    }

    public function testStreamChat_curlFailure_returnsGenericMessage(): void
    {
        CurlFake::$execFails = true;
        CurlFake::$error     = 'Could not resolve host';

        [$result] = $this->stream();

        $this->assertFalse($result['success']);
        $this->assertSame(GroqClient::GENERIC_ERROR_MESSAGE, $result['userMessage']);
    }

    public function testStreamChat_curlErrorStringWithTrueExec_isFailure(): void
    {
        CurlFake::$error = 'timeout';

        [$result] = $this->stream();

        $this->assertFalse($result['success']);
    }

    public function testExtractApiError_prefersMetadataRaw(): void
    {
        $body = json_encode(['error' => ['message' => 'm', 'metadata' => ['raw' => 'raw-detail']]]);
        $this->assertSame('raw-detail', GroqClient::extractApiError((string)$body));
    }

    public function testExtractApiError_fallsBackToMessage(): void
    {
        $body = json_encode(['error' => ['message' => 'plain message']]);
        $this->assertSame('plain message', GroqClient::extractApiError((string)$body));
    }

    public function testExtractApiError_nonJsonReturnsBody_andIsTruncatedTo500(): void
    {
        $this->assertSame('not json', GroqClient::extractApiError('not json'));
        $this->assertSame(500, mb_strlen(GroqClient::extractApiError(str_repeat('あ', 600))));
    }

    public function testExtractApiError_jsonWithoutMessage_returnsRawBody(): void
    {
        $this->assertSame('{"a":1}', GroqClient::extractApiError('{"a":1}'));
    }
}
