<?php
declare(strict_types=1);

namespace App\Test\TestCase\Log\Engine;

use App\Log\Engine\SlackLogEngine;
use Cake\Http\Client;
use Cake\Http\Client\Response;
use Cake\TestSuite\TestCase;

/**
 * SlackLogEngine のテスト
 *
 * 確認項目:
 *   - SLACK_ERROR_WEBHOOK 未設定時はHTTP通信を行わない
 *   - error / critical / alert / emergency の各レベルで Slack に POST される
 *   - メッセージ・ファイル・行番号・スタックトレースが通知本文に含まれる
 *   - HTTP 例外が発生しても呼び出し元に伝播しない
 */
class SlackLogEngineTest extends TestCase
{
    private const DUMMY_WEBHOOK = 'https://hooks.slack.com/services/dummy';

    private Client $mockClient;
    private SlackLogEngine $engine;

    protected function setUp(): void
    {
        parent::setUp();

        $this->mockClient = $this->createMock(Client::class);
        $this->engine = new SlackLogEngine(['httpClient' => $this->mockClient]);
    }

    protected function tearDown(): void
    {
        // テスト間で env をリセット
        $_SERVER['SLACK_ERROR_WEBHOOK'] = '';
        parent::tearDown();
    }

    /**
     * SLACK_ERROR_WEBHOOK が未設定の場合は POST しない。
     */
    public function testDoesNotPostWhenWebhookIsEmpty(): void
    {
        $_SERVER['SLACK_ERROR_WEBHOOK'] = '';

        $this->mockClient->expects($this->never())->method('post');

        $this->engine->log('error', 'テストエラー');
    }

    /**
     * SLACK_ERROR_WEBHOOK が設定されていれば POST する。
     */
    public function testPostsWhenWebhookIsSet(): void
    {
        $_SERVER['SLACK_ERROR_WEBHOOK'] = self::DUMMY_WEBHOOK;

        $this->mockClient
            ->expects($this->once())
            ->method('post')
            ->with(
                self::DUMMY_WEBHOOK,
                $this->callback(fn(string $body) => str_contains($body, 'テストエラー')),
                ['type' => 'json'],
            )
            ->willReturn(new Response([], ''));

        $this->engine->log('error', 'テストエラー');
    }

    /**
     * @dataProvider levelEmojiProvider
     */
    public function testEmojiBylevel(string $level, string $expectedEmoji): void
    {
        $_SERVER['SLACK_ERROR_WEBHOOK'] = self::DUMMY_WEBHOOK;

        $this->mockClient
            ->expects($this->once())
            ->method('post')
            ->with(
                self::DUMMY_WEBHOOK,
                $this->callback(fn(string $body) => str_contains($body, $expectedEmoji)),
                ['type' => 'json'],
            )
            ->willReturn(new Response([], ''));

        $this->engine->log($level, 'dummy');
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function levelEmojiProvider(): array
    {
        return [
            'emergency' => ['emergency', ':rotating_light:'],
            'alert'     => ['alert',     ':rotating_light:'],
            'critical'  => ['critical',  ':fire:'],
            'error'     => ['error',     ':warning:'],
        ];
    }

    /**
     * context に file・line が含まれる場合は通知本文に出力される。
     */
    public function testIncludesFileAndLineFromContext(): void
    {
        $_SERVER['SLACK_ERROR_WEBHOOK'] = self::DUMMY_WEBHOOK;

        $this->mockClient
            ->expects($this->once())
            ->method('post')
            ->with(
                self::DUMMY_WEBHOOK,
                $this->callback(function (string $body): bool {
                    return str_contains($body, 'SomeClass.php')
                        && str_contains($body, '42');
                }),
                ['type' => 'json'],
            )
            ->willReturn(new Response([], ''));

        $this->engine->log('error', 'エラー', ['file' => 'SomeClass.php', 'line' => 42]);
    }

    /**
     * context に trace（文字列）が含まれる場合は通知本文に出力される。
     */
    public function testIncludesStringTrace(): void
    {
        $_SERVER['SLACK_ERROR_WEBHOOK'] = self::DUMMY_WEBHOOK;

        $this->mockClient
            ->expects($this->once())
            ->method('post')
            ->with(
                self::DUMMY_WEBHOOK,
                $this->callback(fn(string $body) => str_contains($body, '#0 SomeClass')),
                ['type' => 'json'],
            )
            ->willReturn(new Response([], ''));

        $this->engine->log('critical', 'エラー', ['trace' => '#0 SomeClass::method()']);
    }

    /**
     * context に trace（配列）が含まれる場合は先頭5件のみ通知される。
     */
    public function testIncludesArrayTraceUpToFiveItems(): void
    {
        $_SERVER['SLACK_ERROR_WEBHOOK'] = self::DUMMY_WEBHOOK;

        $trace = array_map(fn(int $i) => "#$i FrameClass::method()", range(0, 9));

        $this->mockClient
            ->expects($this->once())
            ->method('post')
            ->with(
                self::DUMMY_WEBHOOK,
                $this->callback(function (string $body) use ($trace): bool {
                    return str_contains($body, $trace[0])
                        && str_contains($body, $trace[4])
                        && !str_contains($body, $trace[5]);
                }),
                ['type' => 'json'],
            )
            ->willReturn(new Response([], ''));

        $this->engine->log('error', 'エラー', ['trace' => $trace]);
    }

    /**
     * HTTP 例外が発生しても呼び出し元に伝播しない。
     */
    public function testDoesNotThrowWhenHttpFails(): void
    {
        $_SERVER['SLACK_ERROR_WEBHOOK'] = self::DUMMY_WEBHOOK;

        $this->mockClient
            ->method('post')
            ->willThrowException(new \RuntimeException('network error'));

        // 例外が伝播しないことを確認（アサーションなし = 正常終了でテスト成功）
        $this->engine->log('error', 'エラー');
        $this->assertTrue(true);
    }
}
