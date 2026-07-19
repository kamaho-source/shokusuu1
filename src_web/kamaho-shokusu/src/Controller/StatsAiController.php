<?php
declare(strict_types=1);

namespace App\Controller;

use App\Application\Tenant\TenantContextHolder;
use App\Infrastructure\AI\OpenRouterClient;
use App\Infrastructure\AI\UserTokenizer;
use App\Service\AiStatsContextService;
use App\Service\AuditLogService;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\InternalErrorException;
use Cake\Http\ServerRequest;
use Cake\Log\Log;

/**
 * 統計AI Controller（管理者専用）
 *
 * 集計統計データをコンテキストとして持つAIチャットを提供する。
 * 外部APIへは集計値のみを送信し、個人単位のデータは含めない。
 */
final class StatsAiController extends AppController
{
    private const OPENROUTER_MODEL   = 'openai/gpt-oss-20b:free';
    private const MESSAGE_LIMIT      = 20;
    /** 監査ログに保存する質問・回答の最大文字数 */
    private const LOG_QUESTION_MAX   = 2000;
    private const LOG_ANSWER_MAX     = 4000;
    /** サニタイズ時のメッセージ本文最大文字数 */
    private const CONTENT_MAX_LENGTH = 2000;

    /** @var array<string, string> 氏名→トークン逆引きマップ（サーバー側マスク用） */
    private array $nameToToken = [];
    /** @var array<string, string> 部屋名→トークン逆引きマップ（サーバー側マスク用） */
    private array $roomToToken = [];

    public function __construct(
        private readonly AiStatsContextService $statsContextService,
        private readonly UserTokenizer $userTokenizer,
        ?ServerRequest $request = null,
        ?string $name = null,
    ) {
        parent::__construct($request, $name);
    }

    public function initialize(): void
    {
        parent::initialize();
        // JSONボディを受け取るAJAXエンドポイントのためフォームトークン検証対象外にする。
        // CSRF保護は CsrfProtectionMiddleware がミドルウェア層で適用済み。
        $this->FormProtection->setConfig('unlockedActions', ['askStream']);

        // 氏名→トークンの逆引きマップを構築（askStream でのサーバー側マスクに使用）
        $initCtx = TenantContextHolder::get();
        $usersInitQuery = $this->fetchTable('MUserInfo')->find('list', [
            'keyField'   => 'i_id_user',
            'valueField' => 'c_user_name',
        ])->where(['i_del_flag' => 0]);
        if ($initCtx !== null) {
            $usersInitQuery->where(['tenant_id' => $initCtx->tenantId()]);
        }
        $users = $usersInitQuery->toArray();
        foreach ($users as $id => $name) {
            $this->nameToToken[(string)$name] = $this->userTokenizer->tokenize((int)$id);
        }

        // 部屋名→トークンの逆引きマップを構築（askStream でのサーバー側マスクに使用）
        $roomsInitQuery = $this->fetchTable('MRoomInfo')->find('list', [
            'keyField'   => 'i_id_room',
            'valueField' => 'c_room_name',
        ]);
        if ($initCtx !== null) {
            $roomsInitQuery->where(['tenant_id' => $initCtx->tenantId()]);
        }
        $rooms = $roomsInitQuery->toArray();
        foreach ($rooms as $id => $name) {
            $this->roomToToken[(string)$name] = $this->userTokenizer->tokenize((int)$id);
        }
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

        $indexCtx = TenantContextHolder::get();
        $usersIndexQuery = $this->fetchTable('MUserInfo')->find('list', [
            'keyField'   => 'i_id_user',
            'valueField' => 'c_user_name',
        ])->where(['i_del_flag' => 0]);
        if ($indexCtx !== null) {
            $usersIndexQuery->where(['tenant_id' => $indexCtx->tenantId()]);
        }
        $users = $usersIndexQuery->toArray();

        // キーを内部IDからハッシュトークンへ付け替える（画面には内部IDを露出しない）
        $userMap = [];
        foreach ($users as $id => $name) {
            $userMap[$this->userTokenizer->tokenize((int)$id)] = $name;
        }

        $roomsIndexQuery = $this->fetchTable('MRoomInfo')->find('list', [
            'keyField'   => 'i_id_room',
            'valueField' => 'c_room_name',
        ]);
        if ($indexCtx !== null) {
            $roomsIndexQuery->where(['tenant_id' => $indexCtx->tenantId()]);
        }
        $rooms = $roomsIndexQuery->toArray();
        $roomMap = [];
        foreach ($rooms as $id => $name) {
            $roomMap[$this->userTokenizer->tokenize((int)$id)] = $name;
        }

        $this->set('title', '統計AI');
        $this->set('userMap', $userMap);
        $this->set('roomMap', $roomMap);
    }

    /**
     * 統計コンテキスト付きでAIへの質問をSSEストリーミング処理する。
     *
     * @return never
     * @throws \Cake\Http\Exception\BadRequestException 入力不正時
     * @throws \Cake\Http\Exception\InternalErrorException API設定不備時
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

        $identity     = $this->request->getAttribute('identity');
        $actorName    = (string)($identity?->get('c_user_name') ?? 'unknown');
        $actorId      = (int)($identity?->get('i_id_user') ?? 0);
        $actorLoginId = (string)($identity?->get('c_login_account') ?? '');
        $ipAddress    = $this->request->clientIp();

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
            $result['success'] ? 1 : 0,
            $actorLoginId
        );

        exit(0);
    }

    /**
     * システムプロンプト（役割定義＋最新の統計コンテキスト）を構築する。
     *
     * @throws \Cake\Http\Exception\InternalErrorException プロンプトファイルが見つからない場合
     */
    private function buildSystemPrompt(): string
    {
        $promptFile = __DIR__ . '/../Infrastructure/AI/Prompts/stats_ai.md';
        if (!file_exists($promptFile)) {
            Log::error('stats_ai.md が見つかりません: ' . $promptFile);
            throw new InternalErrorException('AI機能の設定ファイルが見つかりません。');
        }
        $basePrompt = (string)file_get_contents($promptFile);

        return $basePrompt . "\n\n" . $this->statsContextService->build();
    }

    /**
     * メッセージ配列を検証・サニタイズして返す。
     *
     * user ロールのメッセージは既知の氏名をトークンへ置換し、
     * 個人情報が外部 AI API へ渡らないようにする。
     *
     * @param list<array<string, mixed>> $messages
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
            $content = mb_substr($msgContent, 0, self::CONTENT_MAX_LENGTH);
            if ($msgRole === 'user') {
                $content = $this->maskPersonalNames($content);
            }
            $sanitized[] = ['role' => $msgRole, 'content' => $content];
        }

        return $sanitized;
    }

    /**
     * テキスト内の既知の利用者氏名・部屋名をトークンに置換する。
     *
     * 長い文字列を先に置換することで、部分一致による置換崩れを防ぐ。
     */
    private function maskPersonalNames(string $content): string
    {
        if (!empty($this->nameToToken)) {
            $names = array_keys($this->nameToToken);
            usort($names, static fn(string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
            foreach ($names as $name) {
                if (str_contains($content, $name)) {
                    $content = str_replace($name, '[U:' . $this->nameToToken[$name] . ']', $content);
                }
            }
        }

        if (!empty($this->roomToToken)) {
            $roomNames = array_keys($this->roomToToken);
            usort($roomNames, static fn(string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));
            foreach ($roomNames as $name) {
                if (str_contains($content, $name)) {
                    $content = str_replace($name, '[R:' . $this->roomToToken[$name] . ']', $content);
                }
            }
        }

        return $content;
    }
}
