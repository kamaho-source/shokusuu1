<?php
declare(strict_types=1);

namespace App\Controller;

use App\Application\AI\AiUserRole;
use App\Application\AI\SystemPromptProviderInterface;
use App\Domain\ValueObject\UserRole;
use App\Service\AuditLogService;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\InternalErrorException;
use Cake\Http\Response;
use Cake\Http\ServerRequest;
use Cake\Log\Log;

/**
 * AiAssistant Controller
 *
 * @throws \Cake\Http\Exception\BadRequestException 入力不正時
 * @throws \Cake\Http\Exception\InternalErrorException API接続失敗時
 */
class AiAssistantController extends AppController
{
    private const OPENROUTER_MODEL    = 'google/gemma-4-31b-it:free';
    private const OPENROUTER_ENDPOINT = 'https://openrouter.ai/api/v1/chat/completions';
    private const HTTP_REFERER        = 'https://github.com/kamaho-source/shokusuu1';
    private const MESSAGE_LIMIT       = 20;
    /** 監査ログに保存する質問・回答の最大文字数（c_detail の肥大化防止） */
    private const LOG_QUESTION_MAX    = 2000;
    private const LOG_ANSWER_MAX      = 4000;

    public function __construct(
        private readonly SystemPromptProviderInterface $systemPromptProvider,
        ServerRequest $request = null,
        ?string $name = null,
    ) {
        parent::__construct($request, $name);
    }

    public function initialize(): void
    {
        parent::initialize();
        if ($this->components()->has('FormProtection')) {
            $this->FormProtection->setConfig('unlockedActions', ['ask', 'askStream', 'feedback']);
        }
    }

    /**
     * AIへの質問を処理する（従来の非ストリーミング・シングルターン）
     *
     * @return Response
     */
    public function ask(): Response
    {
        $this->request->allowMethod(['post']);
        $this->Authorization->authorize($this, 'ask');

        $question = $this->request->getData('question');
        $context  = $this->request->getData('context');

        if (empty($question)) {
            throw new BadRequestException('質問内容が空です。');
        }

        $apiKey = env('OPENROUTER_API_KEY');
        if (empty($apiKey)) {
            Log::error('OPENROUTER_API_KEY is not set in .env');
            throw new InternalErrorException('AI機能の設定が不十分です。');
        }

        $prompt = $this->buildPrompt($question, $context);
        $role   = $this->resolveAiUserRole();

        $identity  = $this->request->getAttribute('identity');
        $actorName = (string)($identity?->get('c_user_name') ?? 'unknown');
        $actorId   = (int)($identity?->get('i_id_user') ?? 0);
        $ipAddress = $this->request->clientIp();

        try {
            $answer = $this->callOpenRouter([
                ['role' => 'system', 'content' => $this->systemPromptProvider->get($role)],
                ['role' => 'user',   'content' => $prompt],
            ], $apiKey);

            AuditLogService::record(
                'system',
                'ai_assistant_ask',
                $actorName,
                $actorId,
                null,
                null,
                [
                    'question' => mb_substr((string)$question, 0, self::LOG_QUESTION_MAX),
                    'answer'   => mb_substr((string)$answer, 0, self::LOG_ANSWER_MAX),
                    'role'     => $role,
                ],
                $ipAddress
            );

            return $this->response->withType('application/json')
                ->withStringBody(json_encode(['ok' => true, 'answer' => $answer]));
        } catch (\Exception $e) {
            Log::error('AI Assistant error: ' . $e->getMessage());
            AuditLogService::record(
                'system',
                'ai_assistant_ask',
                $actorName,
                $actorId,
                null,
                null,
                [
                    'question' => mb_substr((string)$question, 0, self::LOG_QUESTION_MAX),
                    'error'    => mb_substr($e->getMessage(), 0, 500),
                    'role'     => $role,
                ],
                $ipAddress,
                0
            );
            throw new InternalErrorException('通信エラーが発生しました。');
        }
    }

    /**
     * AIへの質問をSSEストリーミングで処理する（マルチターン対応）
     *
     * フロントエンドから JSON ボディで messages 配列を受け取り、
     * OpenRouter API にストリーミングリクエストを送信してチャンク単位で返す。
     *
     * @return never
     */
    public function askStream(): never
    {
        $this->autoRender = false;
        $this->request->allowMethod(['post']);
        $this->Authorization->authorize($this, 'ask');

        $apiKey = env('OPENROUTER_API_KEY');
        if (empty($apiKey)) {
            Log::error('OPENROUTER_API_KEY is not set in .env');
            throw new InternalErrorException('AI機能の設定が不十分です。');
        }

        $rawBody  = (string)$this->request->getBody();
        $body     = json_decode($rawBody, true) ?? [];
        $messages = $body['messages'] ?? [];

        if (!is_array($messages) || empty($messages)) {
            throw new BadRequestException('会話データが不正です。');
        }

        $sanitized = $this->sanitizeMessages($messages);
        if (empty($sanitized)) {
            throw new BadRequestException('有効な質問が含まれていません。');
        }

        $role         = $this->resolveAiUserRole();
        $systemPrompt = $this->systemPromptProvider->get($role);
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

        $fullResponse = '';
        $success      = $this->streamFromOpenRouter($apiMessages, $apiKey, $fullResponse);

        if (!$success) {
            echo 'data: ' . json_encode(['error' => '通信エラーが発生しました。']) . "\n\n";
            flush();
        }

        AuditLogService::record(
            'system',
            'ai_assistant_ask',
            $actorName,
            $actorId,
            null,
            null,
            [
                'question'        => mb_substr($lastQuestion, 0, self::LOG_QUESTION_MAX),
                'answer'          => mb_substr($fullResponse, 0, self::LOG_ANSWER_MAX),
                'question_length' => mb_strlen($lastQuestion),
                'response_length' => mb_strlen($fullResponse),
                'role'            => $role,
            ],
            $ipAddress,
            $success ? 1 : 0
        );

        exit(0);
    }

    /**
     * ロール別サジェスト質問一覧を返す
     *
     * @return Response
     */
    public function suggestions(): Response
    {
        $this->request->allowMethod(['get']);
        $this->Authorization->authorize($this, 'ask');

        $role = $this->resolveAiUserRole();
        $file = __DIR__ . '/../Infrastructure/AI/Prompts/suggestions/' . $role . '.json';

        $suggestions = [];
        if (file_exists($file)) {
            $json        = file_get_contents($file);
            $suggestions = ($json !== false) ? (json_decode($json, true) ?? []) : [];
        }

        return $this->response->withType('application/json')
            ->withStringBody(json_encode(['ok' => true, 'suggestions' => $suggestions]));
    }

    /**
     * AI回答へのフィードバックを記録する
     *
     * @return Response
     */
    public function feedback(): Response
    {
        $this->request->allowMethod(['post']);
        $this->Authorization->authorize($this, 'ask');

        $rawBody = (string)$this->request->getBody();
        $body    = json_decode($rawBody, true) ?? [];
        $rating  = $body['rating'] ?? '';

        if (!in_array($rating, ['good', 'bad'], true)) {
            throw new BadRequestException('フィードバック値が不正です。');
        }

        $identity  = $this->request->getAttribute('identity');
        $actorName = (string)($identity?->get('c_user_name') ?? 'unknown');
        $actorId   = (int)($identity?->get('i_id_user') ?? 0);
        $ipAddress = $this->request->clientIp();

        AuditLogService::record(
            'system',
            'ai_assistant_feedback',
            $actorName,
            $actorId,
            null,
            null,
            [
                'rating'          => $rating,
                'question_length' => (int)($body['question_length'] ?? 0),
                'answer_length'   => (int)($body['answer_length']   ?? 0),
            ],
            $ipAddress
        );

        return $this->response->withType('application/json')
            ->withStringBody(json_encode(['ok' => true]));
    }

    /**
     * ログイン中ユーザーの権限から AiUserRole を解決する。
     */
    private function resolveAiUserRole(): string
    {
        $user = $this->request->getAttribute('identity');
        if (!$user) {
            return AiUserRole::GENERAL;
        }

        $iAdmin     = (int)($user->get('i_admin')      ?? 0);
        $iUserLevel = (int)($user->get('i_user_level') ?? 0);

        if ($iUserLevel === 1) {
            return AiUserRole::CHILD;
        }

        if (UserRole::isAdmin($iAdmin) || UserRole::isSystemAdmin($iAdmin)) {
            return AiUserRole::ADMIN;
        }

        if (UserRole::isBlockLeader($iAdmin)) {
            return AiUserRole::BLOCK_LEADER;
        }

        return AiUserRole::GENERAL;
    }

    /**
     * ユーザーの質問と文脈を組み合わせたプロンプトを構築する
     */
    private function buildPrompt(string $question, ?string $context): string
    {
        $prompt = '';
        if ($context) {
            $prompt .= "【現在の画面コンテキスト】\n" . $context . "\n\n";
        }
        $prompt .= "【ユーザーの質問】\n" . $question;

        return $prompt;
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

    /**
     * OpenRouter API を非ストリーミングで呼び出して回答テキストを返す。
     *
     * @param list<array{role: string, content: string}> $messages
     * @param string $apiKey
     * @return string
     * @throws \RuntimeException API呼び出し失敗時
     */
    private function callOpenRouter(array $messages, string $apiKey): string
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => self::OPENROUTER_ENDPOINT,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'model'       => self::OPENROUTER_MODEL,
                'messages'    => $messages,
                'temperature' => 0.7,
            ]),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
                'HTTP-Referer: ' . self::HTTP_REFERER,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 60,
        ]);

        $result    = curl_exec($ch);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($result === false || $curlError !== '') {
            throw new \RuntimeException('curl error: ' . $curlError);
        }

        $json = json_decode((string)$result, true);
        return (string)($json['choices'][0]['message']['content'] ?? '回答を取得できませんでした。');
    }

    /**
     * OpenRouter API をストリーミングで呼び出し、SSE チャンクを標準出力に書き出す。
     *
     * @param list<array{role: string, content: string}> $apiMessages
     * @param string $apiKey
     * @param string $fullResponse 蓄積された応答テキスト（参照渡し）
     * @return bool curl 成功時 true
     */
    private function streamFromOpenRouter(array $apiMessages, string $apiKey, string &$fullResponse): bool
    {
        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => self::OPENROUTER_ENDPOINT,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'model'       => self::OPENROUTER_MODEL,
                'messages'    => $apiMessages,
                'temperature' => 0.7,
                'stream'      => true,
            ]),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
                'HTTP-Referer: ' . self::HTTP_REFERER,
            ],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_WRITEFUNCTION  => function ($ch, string $data) use (&$fullResponse): int {
                $lines = explode("\n", $data);
                foreach ($lines as $line) {
                    $trimmed = trim($line);
                    if ($trimmed === '' || !str_starts_with($trimmed, 'data: ')) {
                        continue;
                    }
                    $payload = substr($trimmed, 6);
                    if ($payload === '[DONE]') {
                        echo "data: [DONE]\n\n";
                        flush();
                        continue;
                    }
                    $decoded = json_decode($payload, true);
                    if (!is_array($decoded)) {
                        continue;
                    }
                    $content = $decoded['choices'][0]['delta']['content'] ?? null;
                    if ($content !== null) {
                        $fullResponse .= $content;
                        echo 'data: ' . json_encode(
                            ['content' => $content],
                            JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES
                        ) . "\n\n";
                        flush();
                    }
                }
                return strlen($data);
            },
            CURLOPT_TIMEOUT        => 60,
        ]);

        $execResult = curl_exec($ch);
        $curlError  = curl_error($ch);
        curl_close($ch);

        if ($execResult === false) {
            Log::error('AI stream curl error: ' . $curlError);
        }

        return $execResult !== false && $curlError === '';
    }
}
