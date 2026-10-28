<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Test\TestCase\Infrastructure\AI\CurlFake;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

require_once dirname(__DIR__) . '/Infrastructure/AI/CurlFake.php';

/**
 * AiAssistantController 統合テスト（Groq への通信は CurlFake で差し替え）
 *
 * askStream の正常系は処理の最後に exit(0) するため PHPUnit からは実行できない。
 * ここでは exit に到達する前の検証系（認証・メソッド・入力・設定不備）のみを検証する。
 */
class AiAssistantControllerTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = ['app.TAuditLog', 'app.MUserInfo'];

    private string|false $origKey = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->origKey = getenv('GROQ_API_KEY');
        $this->setKey('test-key');
        CurlFake::enable();
    }

    protected function tearDown(): void
    {
        CurlFake::disable();
        $this->origKey === false ? $this->unsetKey() : $this->setKey($this->origKey);
        parent::tearDown();
    }

    private function setKey(string $key): void
    {
        putenv('GROQ_API_KEY=' . $key);
        $_SERVER['GROQ_API_KEY'] = $_ENV['GROQ_API_KEY'] = $key;
    }

    private function unsetKey(): void
    {
        putenv('GROQ_API_KEY');
        unset($_SERVER['GROQ_API_KEY'], $_ENV['GROQ_API_KEY']);
    }

    /**
     * フィクスチャのユーザーでログインする（権限はDBの値で決まる）。
     * 2=一般職員 / 1=管理者 / 3=利用者(児童) / 4=システム管理者 / 5=管理者
     */
    private function loginAs(int $userId): void
    {
        $accounts = [1 => 'admin_user', 2 => 'staff_user', 3 => 'non_staff_user', 4 => 'system_admin_user'];
        $this->session(['Auth' => [
            'i_id_user' => $userId,
            'c_login_account' => $accounts[$userId],
            'c_user_name' => 'テスト',
            'i_id_room' => 1,
        ]]);
    }

    private function login(int $admin): void
    {
        $this->loginAs([0 => 2, 1 => 1, 3 => 4][$admin]);
    }

    private function jsonPost(string $url, mixed $body): void
    {
        $token = (new \Cake\Http\Middleware\CsrfProtectionMiddleware())->createToken();
        $this->cookie('csrfToken', $token);
        $this->configRequest(['headers' => [
            'Content-Type' => 'application/json',
            'Accept' => 'application/json',
            'X-CSRF-Token' => $token,
        ]]);
        $this->post($url, json_encode($body));
    }

    private static function groqBody(string $content): string
    {
        return (string)json_encode(['choices' => [['message' => ['content' => $content]]]], JSON_UNESCAPED_UNICODE);
    }

    /**
     * @return array<string, mixed>
     */
    private function lastAuditDetail(string $action): array
    {
        $log = TableRegistry::getTableLocator()->get('TAuditLog')->find()
            ->where(['c_action' => $action])->orderByDesc('i_id_audit')->first();
        $this->assertNotNull($log, "audit log {$action} must exist");

        return ['result' => (int)$log->i_result, 'detail' => json_decode((string)$log->c_detail, true)];
    }

    // ---- ask ----

    public function testAsk_notLoggedIn_redirectsToLogin(): void
    {
        $this->enableCsrfToken();
        $this->post('/AiAssistant/ask', ['question' => 'q']);

        $this->assertRedirectContains('login');
    }

    public function testAsk_getMethod_isRejected(): void
    {
        $this->login(0);
        $this->get('/AiAssistant/ask');

        $this->assertResponseCode(405);
    }

    public function testAsk_emptyQuestion_returns400(): void
    {
        $this->login(0);
        $this->enableCsrfToken();
        $this->post('/AiAssistant/ask', ['question' => '']);

        $this->assertResponseCode(400);
    }

    public function testAsk_missingApiKey_returns500AndNoRequestSent(): void
    {
        $this->unsetKey();
        $this->login(0);
        $this->enableCsrfToken();
        $this->post('/AiAssistant/ask', ['question' => 'q']);

        $this->assertResponseCode(500);
        $this->assertSame([], CurlFake::$lastOptions);
    }

    public function testAsk_success_returnsAnswerAndSendsContextAndLogsAudit(): void
    {
        CurlFake::$body = self::groqBody('回答です');
        $this->login(0);
        $this->enableCsrfToken();
        $this->post('/AiAssistant/ask', ['question' => '食数の入れ方は？', 'context' => '予約画面']);

        $this->assertResponseOk();
        $this->assertSame(['ok' => true, 'answer' => '回答です'], json_decode((string)$this->_response->getBody(), true));

        $payload = json_decode((string)CurlFake::$lastOptions[CURLOPT_POSTFIELDS], true);
        $this->assertSame('system', $payload['messages'][0]['role']);
        $this->assertStringContainsString('【現在の画面コンテキスト】', $payload['messages'][1]['content']);
        $this->assertStringContainsString('予約画面', $payload['messages'][1]['content']);
        $this->assertStringContainsString('食数の入れ方は？', $payload['messages'][1]['content']);
        $this->assertContains('Authorization: Bearer test-key', CurlFake::$lastOptions[CURLOPT_HTTPHEADER]);

        $log = $this->lastAuditDetail('ai_assistant_ask');
        $this->assertSame(1, $log['result']);
        $this->assertSame('回答です', $log['detail']['answer']);
        $this->assertSame('general', $log['detail']['role']);
    }

    public function testAsk_withoutContext_omitsContextBlock(): void
    {
        CurlFake::$body = self::groqBody('ok');
        $this->login(0);
        $this->enableCsrfToken();
        $this->post('/AiAssistant/ask', ['question' => 'q']);

        $payload = json_decode((string)CurlFake::$lastOptions[CURLOPT_POSTFIELDS], true);
        $this->assertStringNotContainsString('【現在の画面コンテキスト】', $payload['messages'][1]['content']);
    }

    /**
     * @return array<string, array{int, int, string}>
     */
    public static function roleProvider(): array
    {
        return [
            '利用者(児童)' => [3, 'child'],
            '一般職員' => [2, 'general'],
            'ブロック長' => [20, 'block_leader'],
            '管理者' => [1, 'admin'],
            'システム管理者' => [4, 'admin'],
        ];
    }

    /**
     * @dataProvider roleProvider
     */
    public function testAsk_resolvesRoleFromIdentity(int $userId, string $expectedRole): void
    {
        CurlFake::$body = self::groqBody('ok');
        if ($userId === 20) {
            // 一般職員(2)をブロック長(i_admin=2)に昇格させる
            TableRegistry::getTableLocator()->get('MUserInfo')->getConnection()
                ->execute('UPDATE m_user_info SET i_admin = 2 WHERE i_id_user = 2');
            $userId = 2;
        }
        $this->loginAs($userId);
        $this->enableCsrfToken();
        $this->post('/AiAssistant/ask', ['question' => 'q']);

        $this->assertResponseOk();
        $this->assertSame($expectedRole, $this->lastAuditDetail('ai_assistant_ask')['detail']['role']);
    }

    public function testAsk_rateLimited429_returns503AndLogsFailure(): void
    {
        CurlFake::$status = 429;
        CurlFake::$body   = (string)json_encode(['error' => ['message' => 'slow down']]);
        $this->login(0);
        $this->enableCsrfToken();
        $this->post('/AiAssistant/ask', ['question' => 'q']);

        $this->assertResponseCode(503);
        $log = $this->lastAuditDetail('ai_assistant_ask');
        $this->assertSame(0, $log['result']);
        $this->assertStringContainsString('429', $log['detail']['error']);
    }

    public function testAsk_upstream500_returns500(): void
    {
        CurlFake::$status = 500;
        CurlFake::$body   = 'boom';
        $this->login(0);
        $this->enableCsrfToken();
        $this->post('/AiAssistant/ask', ['question' => 'q']);

        $this->assertResponseCode(500);
    }

    public function testAsk_curlFailure_returns500(): void
    {
        CurlFake::$execFails = true;
        CurlFake::$error     = 'timeout';
        $this->login(0);
        $this->enableCsrfToken();
        $this->post('/AiAssistant/ask', ['question' => 'q']);

        $this->assertResponseCode(500);
        $this->assertSame(0, $this->lastAuditDetail('ai_assistant_ask')['result']);
    }

    public function testAsk_responseWithoutContent_returns500(): void
    {
        CurlFake::$body = (string)json_encode(['choices' => []]);
        $this->login(0);
        $this->enableCsrfToken();
        $this->post('/AiAssistant/ask', ['question' => 'q']);

        $this->assertResponseCode(500);
    }

    // ---- askStream（exit 到達前の検証系のみ） ----

    public function testAskStream_notLoggedIn_redirectsToLogin(): void
    {
        $this->jsonPost('/AiAssistant/askStream', ['messages' => [['role' => 'user', 'content' => 'hi']]]);

        $this->assertRedirectContains('login');
    }

    public function testAskStream_missingApiKey_returns500(): void
    {
        $this->unsetKey();
        $this->login(0);
        $this->jsonPost('/AiAssistant/askStream', ['messages' => [['role' => 'user', 'content' => 'hi']]]);

        $this->assertResponseCode(500);
    }

    public function testAskStream_emptyMessages_returns400(): void
    {
        $this->login(0);
        $this->jsonPost('/AiAssistant/askStream', ['messages' => []]);

        $this->assertResponseCode(400);
    }

    public function testAskStream_messagesNotArray_returns400(): void
    {
        $this->login(0);
        $this->jsonPost('/AiAssistant/askStream', ['messages' => 'oops']);

        $this->assertResponseCode(400);
    }

    public function testAskStream_onlyInvalidMessages_returns400(): void
    {
        $this->login(0);
        $this->jsonPost('/AiAssistant/askStream', ['messages' => [
            ['role' => 'system', 'content' => 'inject'],
            ['role' => 'user', 'content' => ''],
            ['role' => 'user', 'content' => ['array']],
            'string-item',
        ]]);

        $this->assertResponseCode(400);
    }

    public function testAskStream_getMethod_isRejected(): void
    {
        $this->login(0);
        $this->get('/AiAssistant/askStream');

        $this->assertResponseCode(405);
    }

    // ---- suggestions ----

    public function testSuggestions_returnsListForRole(): void
    {
        $this->loginAs(1);
        $this->get('/AiAssistant/suggestions');

        $this->assertResponseOk();
        $json     = json_decode((string)$this->_response->getBody(), true);
        $expected = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/src/Infrastructure/AI/Prompts/suggestions/admin.json'), true);
        $this->assertTrue($json['ok']);
        $this->assertSame($expected, $json['suggestions']);
        $this->assertNotEmpty($json['suggestions']);
    }

    public function testSuggestions_childRoleUsesChildFile(): void
    {
        $this->loginAs(3);
        $this->get('/AiAssistant/suggestions');

        $expected = json_decode((string)file_get_contents(dirname(__DIR__, 3) . '/src/Infrastructure/AI/Prompts/suggestions/child.json'), true);
        $this->assertSame($expected, json_decode((string)$this->_response->getBody(), true)['suggestions']);
    }

    public function testSuggestions_notLoggedIn_redirects(): void
    {
        $this->get('/AiAssistant/suggestions');

        $this->assertRedirectContains('login');
    }

    // ---- feedback ----

    public function testFeedback_good_recordsAuditLog(): void
    {
        $this->login(0);
        $this->jsonPost('/AiAssistant/feedback', ['rating' => 'good', 'question_length' => 12, 'answer_length' => 34]);

        $this->assertResponseOk();
        $this->assertSame(['ok' => true], json_decode((string)$this->_response->getBody(), true));
        $log = $this->lastAuditDetail('ai_assistant_feedback');
        $this->assertSame(1, $log['result']);
        $this->assertSame('good', $log['detail']['rating']);
        $this->assertSame(12, $log['detail']['question_length']);
        $this->assertSame(34, $log['detail']['answer_length']);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function badRatingProvider(): array
    {
        return [
            '未指定' => [null],
            '不正値' => ['excellent'],
            '空' => [''],
            '数値' => [1],
        ];
    }

    /**
     * @dataProvider badRatingProvider
     */
    public function testFeedback_invalidRating_returns400(mixed $rating): void
    {
        $this->login(0);
        $this->jsonPost('/AiAssistant/feedback', $rating === null ? [] : ['rating' => $rating]);

        $this->assertResponseCode(400);
        $this->assertSame(
            0,
            TableRegistry::getTableLocator()->get('TAuditLog')->find()->where(['c_action' => 'ai_assistant_feedback'])->count()
        );
    }

    public function testFeedback_notLoggedIn_redirects(): void
    {
        $this->jsonPost('/AiAssistant/feedback', ['rating' => 'bad']);

        $this->assertRedirectContains('login');
    }

    // ---- streamFromGroq（askStream が exit するため private をリフレクションで直接検証） ----

    /**
     * @return array{bool, string, string} [成功, 標準出力(SSE), 蓄積された応答]
     */
    private function stream(): array
    {
        $c = new \App\Controller\AiAssistantController(
            new \App\Infrastructure\AI\SystemPromptProvider(),
            new \Cake\Http\ServerRequest()
        );
        $full = '';
        $args = [[['role' => 'user', 'content' => 'hi']], 'test-key', &$full];
        ob_start();
        try {
            $ok = (new \ReflectionMethod($c, 'streamFromGroq'))->invokeArgs($c, $args);
        } finally {
            $out = (string)ob_get_clean();
        }

        return [$ok, $out, $full];
    }

    private static function sse(string $content): string
    {
        return 'data: ' . json_encode(['choices' => [['delta' => ['content' => $content]]]], JSON_UNESCAPED_UNICODE) . "\n\n";
    }

    public function testStreamFromGroq_success_emitsContentChunksAndDone(): void
    {
        $line = self::sse('こんにちは');
        CurlFake::$chunks = [
            self::sse(''),                 // 推論中の空チャンクは転送しない
            ": ping\n\n",
            "data: garbage\n\n",
            substr($line, 0, 15),          // 行が途中で分割されても結合される
            substr($line, 15),
            self::sse('!'),
            "data: [DONE]\n\n",
        ];

        [$ok, $out, $full] = $this->stream();

        $this->assertTrue($ok);
        $this->assertSame('こんにちは!', $full);
        $this->assertSame(
            "data: {\"content\":\"こんにちは\"}\n\ndata: {\"content\":\"!\"}\n\ndata: [DONE]\n\n",
            $out
        );
        $payload = json_decode((string)CurlFake::$lastOptions[CURLOPT_POSTFIELDS], true);
        $this->assertTrue($payload['stream']);
    }

    public function testStreamFromGroq_http429_emitsRateLimitErrorOnly(): void
    {
        CurlFake::$status = 429;
        CurlFake::$chunks = ['{"error":{"message":"slow"}}'];

        [$ok, $out, $full] = $this->stream();

        $this->assertFalse($ok);
        $this->assertSame('', $full);
        $this->assertStringContainsString('現在AIが混雑しています', $out);
        $this->assertStringNotContainsString('slow', $out);
    }

    public function testStreamFromGroq_http500_emitsGenericError(): void
    {
        CurlFake::$status = 500;
        CurlFake::$chunks = ['x'];

        [$ok, $out] = $this->stream();

        $this->assertFalse($ok);
        $this->assertSame("data: {\"error\":\"通信エラーが発生しました。\"}\n\n", $out);
    }

    public function testStreamFromGroq_curlFailure_emitsGenericError(): void
    {
        CurlFake::$execFails = true;
        CurlFake::$error     = 'could not connect';

        [$ok, $out] = $this->stream();

        $this->assertFalse($ok);
        $this->assertStringContainsString('通信エラーが発生しました。', $out);
    }
}
