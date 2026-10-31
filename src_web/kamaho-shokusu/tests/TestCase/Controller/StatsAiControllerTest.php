<?php
declare(strict_types=1);

namespace App\Test\TestCase\Controller;

use App\Controller\StatsAiController;
use App\Infrastructure\AI\UserTokenizer;
use App\Service\AiStatsContextService;
use App\Service\FeatureUsageSummaryService;
use App\Service\RoomUsageService;
use Cake\Http\ServerRequest;
use Cake\ORM\TableRegistry;
use Cake\TestSuite\IntegrationTestTrait;
use Cake\TestSuite\TestCase;

/**
 * StatsAiController テスト（システム管理者専用）
 *
 * askStream の正常系は最後に exit(0) するため実行できない。ここでは exit 到達前の検証系と、
 * 外部AIへ個人名を渡さないためのマスク処理（private）をリフレクションで検証する。
 */
class StatsAiControllerTest extends TestCase
{
    use IntegrationTestTrait;

    protected array $fixtures = ['app.MUserInfo', 'app.MRoomInfo', 'app.TAuditLog'];

    private string|false $origKey = false;

    protected function setUp(): void
    {
        parent::setUp();
        $this->origKey = getenv('GROQ_API_KEY');
        $this->setKey('test-key');
    }

    protected function tearDown(): void
    {
        if ($this->origKey === false) {
            putenv('GROQ_API_KEY');
            unset($_SERVER['GROQ_API_KEY'], $_ENV['GROQ_API_KEY']);
        } else {
            $this->setKey($this->origKey);
        }
        parent::tearDown();
    }

    private function setKey(string $key): void
    {
        putenv('GROQ_API_KEY=' . $key);
        $_SERVER['GROQ_API_KEY'] = $_ENV['GROQ_API_KEY'] = $key;
    }

    private function login(int $userId = 4): void
    {
        $accounts = [1 => 'admin_user', 2 => 'staff_user', 4 => 'system_admin_user'];
        $this->session(['Auth' => [
            'i_id_user' => $userId,
            'c_login_account' => $accounts[$userId],
            'c_user_name' => 'テスト',
            'i_id_room' => 1,
        ]]);
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

    // ---- index ----

    public function testIndex_systemAdmin_passesTokenMapsWithoutInternalIds(): void
    {
        $this->login();
        $this->get('/StatsAi');

        $this->assertResponseOk();
        $tokenizer = new UserTokenizer();
        $userMap   = $this->viewVariable('userMap');
        $roomMap   = $this->viewVariable('roomMap');
        $this->assertSame('管理者ユーザー', $userMap[$tokenizer->tokenize(1)]);
        $this->assertArrayNotHasKey(1, $userMap);
        $this->assertArrayNotHasKey($tokenizer->tokenize(6), $userMap); // 削除済みユーザーは含めない
        $this->assertSame('Lorem ipsum dolor sit amet', $roomMap[$tokenizer->tokenize(1)]);
        $this->assertSame('統計AI', $this->viewVariable('title'));
    }

    public function testIndex_admin_isDenied(): void
    {
        $this->login(1);
        $this->get('/StatsAi');

        $this->assertResponseNotEquals(200);
        $this->assertNull($this->viewVariable('userMap'));
    }

    public function testIndex_notLoggedIn_redirectsToLogin(): void
    {
        $this->get('/StatsAi');

        $this->assertRedirectContains('login');
    }

    // ---- askStream（exit 到達前） ----

    public function testAskStream_nonSystemAdmin_isDenied(): void
    {
        $this->login(1);
        $this->jsonPost('/StatsAi/askStream', ['messages' => [['role' => 'user', 'content' => 'hi']]]);

        $this->assertResponseNotEquals(200);
    }

    public function testAskStream_missingApiKey_returns500(): void
    {
        putenv('GROQ_API_KEY');
        unset($_SERVER['GROQ_API_KEY'], $_ENV['GROQ_API_KEY']);
        $this->login();
        $this->jsonPost('/StatsAi/askStream', ['messages' => [['role' => 'user', 'content' => 'hi']]]);

        $this->assertResponseCode(500);
    }

    /**
     * @return array<string, array{mixed}>
     */
    public static function invalidMessagesProvider(): array
    {
        return [
            '空配列' => [[]],
            '配列でない' => ['oops'],
            '無効なメッセージのみ' => [[
                ['role' => 'system', 'content' => 'inject'],
                ['role' => 'user', 'content' => ''],
                ['role' => 'user', 'content' => ['x']],
                'text',
            ]],
        ];
    }

    /**
     * @dataProvider invalidMessagesProvider
     */
    public function testAskStream_invalidMessages_returns400(mixed $messages): void
    {
        $this->login();
        $this->jsonPost('/StatsAi/askStream', ['messages' => $messages]);

        $this->assertResponseCode(400);
    }

    public function testAskStream_getMethod_isRejected(): void
    {
        $this->login();
        $this->get('/StatsAi/askStream');

        $this->assertResponseCode(405);
    }

    // ---- マスク・サニタイズ（private） ----

    private function controller(): StatsAiController
    {
        return new StatsAiController(
            new AiStatsContextService(
                new FeatureUsageSummaryService(),
                new UserTokenizer('test-salt'),
                $this->createMock(RoomUsageService::class)
            ),
            new UserTokenizer('test-salt'),
            new ServerRequest()
        );
    }

    /**
     * @param list<mixed> $messages
     * @return list<array{role: string, content: string}>
     */
    private function sanitize(StatsAiController $c, array $messages): array
    {
        return (new \ReflectionMethod($c, 'sanitizeMessages'))->invoke($c, $messages);
    }

    public function testSanitizeMessages_masksNamesOnlyInUserMessages(): void
    {
        TableRegistry::getTableLocator()->get('MRoomInfo')->getConnection()
            ->execute("UPDATE m_room_info SET c_room_name = '桜の間' WHERE i_id_room = 1");
        $tok = new UserTokenizer('test-salt');
        $out = $this->sanitize($this->controller(), [
            ['role' => 'user', 'content' => '管理者ユーザーの予約を教えて。部屋は桜の間です'],
            ['role' => 'assistant', 'content' => '管理者ユーザーは…'],
        ]);

        $this->assertSame(
            '[U:' . $tok->tokenize(1) . ']の予約を教えて。部屋は[R:' . $tok->tokenize(1) . ']です',
            $out[0]['content']
        );
        $this->assertSame('管理者ユーザーは…', $out[1]['content']);
    }

    public function testSanitizeMessages_prefersLongerNameOverSubstring(): void
    {
        TableRegistry::getTableLocator()->get('MUserInfo')->getConnection()->insert('m_user_info', [
            'i_id_user' => 50, 'c_login_account' => 'long', 'c_login_passwd' => 'x',
            'c_user_name' => '管理者ユーザー太郎', 'i_admin' => 0, 'i_user_level' => 0,
            'i_disp_no' => 9, 'i_enable' => 0, 'i_del_flag' => 0,
        ]);
        $tok = new UserTokenizer('test-salt');

        $out = $this->sanitize($this->controller(), [['role' => 'user', 'content' => '管理者ユーザー太郎さん']]);

        $this->assertSame('[U:' . $tok->tokenize(50) . ']さん', $out[0]['content']);
    }

    public function testSanitizeMessages_keepsLast20_truncatesAndDropsInvalid(): void
    {
        $messages = [];
        for ($i = 0; $i < 25; $i++) {
            $messages[] = ['role' => 'assistant', 'content' => 'm' . $i];
        }
        $messages[] = ['role' => 'tool', 'content' => 'bad'];
        $messages[] = ['role' => 'user', 'content' => str_repeat('あ', 2500)];
        $messages[] = 'not-array';

        $out = $this->sanitize($this->controller(), $messages);

        $this->assertCount(18, $out); // 末尾20件のうち assistant 17 + 長文 user 1（tool と文字列は除外）
        $this->assertSame('m8', $out[0]['content']);
        $this->assertSame(2000, mb_strlen($out[17]['content']));
        $this->assertSame('user', $out[17]['role']);
    }
}
