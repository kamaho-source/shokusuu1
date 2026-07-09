<?php
declare(strict_types=1);

namespace App\Controller;

use App\Infrastructure\AI\OpenRouterClient;
use App\Infrastructure\AI\UserTokenizer;
use App\Service\AiStatsContextService;
use App\Service\AuditLogService;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\InternalErrorException;
use Cake\Log\Log;

/**
 * 統計AI Controller（管理者専用）
 *
 * 集計統計データをコンテキストとして持つAIチャットを提供する。
 * 外部APIへは集計値のみを送信し、個人単位のデータは含めない。
 *
 * @throws \Cake\Http\Exception\BadRequestException 入力不正時
 * @throws \Cake\Http\Exception\InternalErrorException API設定不備時
 */
class StatsAiController extends AppController
{
    private const OPENROUTER_MODEL = 'openai/gpt-oss-20b:free';
    private const MESSAGE_LIMIT    = 20;
    /** 監査ログに保存する質問・回答の最大文字数 */
    private const LOG_QUESTION_MAX = 2000;
    private const LOG_ANSWER_MAX   = 4000;

    private AiStatsContextService $statsContextService;

    public function initialize(): void
    {
        parent::initialize();
        $this->statsContextService = new AiStatsContextService();
        // JSONボディを受け取るAJAXエンドポイントのためフォームトークン検証対象外にする。
        // CSRF保護は CsrfProtectionMiddleware がミドルウェア層で適用済み。
        $this->FormProtection->setConfig('unlockedActions', ['askStream']);
    }

    /**
     * 統計AIチャット画面を表示する。
     *
     * AI回答内の [U:<ハッシュ>] トークンを画面側で氏名に変換するため、
     * ハッシュトークン→氏名マップをビューに渡す（氏名・内部IDは外部AI APIへは送信されない）。
     */
    public function index(): void
    {
        $this->Authorization->authorize($this, 'index');

        $tokenizer = new UserTokenizer();
        $users = $this->fetchTable('MUserInfo')->find('list', [
            'keyField'   => 'i_id_user',
            'valueField' => 'c_user_name',
        ])->where(['i_del_flag' => 0])->toArray();

        // キーを内部IDからハッシュトークンへ付け替える（画面には内部IDを露出しない）
        $userMap = [];
        foreach ($users as $id => $name) {
            $userMap[$tokenizer->tokenize((int)$id)] = $name;
        }

        $this->set('title', '統計AI');
        $this->set('userMap', $userMap);
    }

    /**
     * 統計コンテキスト付きでAIへの質問をSSEストリーミング処理する。
     *
     * @return never
     */
    public function askStream(): never
    {
        $this->autoRender = false;
        $this->request->allowMethod(['post']);
        $this->Authorization->authorize($this, 'askStream');

        $apiKey = env('OPENROUTER_API_KEY');
        if (empty($apiKey)) {
            Log::error('OPENROUTER_API_KEY is not set in .env');
            throw new InternalErrorException('AI機能の設定が不十分です。');
        }

        $body     = json_decode((string)$this->request->getBody(), true) ?? [];
        $messages = $body['messages'] ?? [];
        if (!is_array($messages) || empty($messages)) {
            throw new BadRequestException('会話データが不正です。');
        }

        $sanitized = $this->sanitizeMessages($messages);
        if (empty($sanitized)) {
            throw new BadRequestException('有効な質問が含まれていません。');
        }

        $systemPrompt = $this->buildSystemPrompt();
        $apiMessages  = array_merge(
            [['role' => 'system', 'content' => $systemPrompt]],
            $sanitized
        );

        $identity  = $this->request->getAttribute('identity');
        $actorName = (string)($identity?->get('c_user_name') ?? 'unknown');
        $actorId   = (int)($identity?->get('i_id_user') ?? 0);
        $ipAddress = $this->request->clientIp();

        $lastQuestion = '';
        foreach (array_reverse($sanitized) as $msg) {
            if ($msg['role'] === 'user') {
                $lastQuestion = $msg['content'];
                break;
            }
        }

        // バリデーション完了後にSSEヘッダーを送信
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        ob_implicit_flush(true);

        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache, no-store');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');

        $client = new OpenRouterClient(self::OPENROUTER_MODEL, (string)$apiKey);
        $result = $client->streamChat($apiMessages, function (string $content): void {
            echo 'data: ' . json_encode(
                ['content' => $content],
                JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
            ) . "\n\n";
            flush();
        });

        if (!$result['success']) {
            echo 'data: ' . json_encode(['error' => $result['userMessage']], JSON_UNESCAPED_UNICODE) . "\n\n";
            flush();
        }
        echo "data: [DONE]\n\n";
        flush();

        AuditLogService::record(
            'system',
            'stats_ai_ask',
            $actorName,
            $actorId,
            null,
            null,
            [
                'question'        => mb_substr($lastQuestion, 0, self::LOG_QUESTION_MAX),
                'answer'          => mb_substr($result['fullResponse'], 0, self::LOG_ANSWER_MAX),
                'question_length' => mb_strlen($lastQuestion),
                'response_length' => mb_strlen($result['fullResponse']),
            ],
            $ipAddress,
            $result['success'] ? 1 : 0
        );

        exit(0);
    }

    /**
     * システムプロンプト（役割定義＋最新の統計コンテキスト）を構築する。
     */
    private function buildSystemPrompt(): string
    {
        $promptFile = __DIR__ . '/../Infrastructure/AI/Prompts/stats_ai.md';
        $basePrompt = file_exists($promptFile) ? (string)file_get_contents($promptFile) : '';

        return $basePrompt . "\n\n" . $this->statsContextService->build();
    }

    /**
     * メッセージ配列を検証・サニタイズして返す。
     *
     * @param array<mixed> $messages
     * @return list<array{role: string, content: string}>
     */
    private function sanitizeMessages(array $messages): array
    {
        $sanitized = [];
        foreach (array_slice($messages, -self::MESSAGE_LIMIT) as $msg) {
            if (!is_array($msg)) {
                continue;
            }
            $msgRole    = $msg['role']    ?? '';
            $msgContent = $msg['content'] ?? '';
            if (!in_array($msgRole, ['user', 'assistant'], true)) {
                continue;
            }
            if (!is_string($msgContent) || $msgContent === '') {
                continue;
            }
            $sanitized[] = ['role' => $msgRole, 'content' => $msgContent];
        }

        return $sanitized;
    }
}
