<?php
declare(strict_types=1);

namespace App\Controller;

use App\Application\AI\AiUserRole;
use App\Application\AI\SystemPromptProviderInterface;
use App\Domain\ValueObject\UserRole;
use Cake\Http\Client;
use Cake\Http\Exception\BadRequestException;
use Cake\Http\Exception\InternalErrorException;
use Cake\Http\ServerRequest;
use Cake\Log\Log;

/**
 * AiAssistant Controller
 */
class AiAssistantController extends AppController
{
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
            $this->FormProtection->setConfig('unlockedActions', ['ask']);
        }
    }

    /**
     * AIへの質問を処理する
     *
     * @return \Cake\Http\Response|null
     */
    public function ask()
    {
        $this->request->allowMethod(['post']);
        $this->Authorization->authorize($this);

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

        $http = new Client();
        try {
            $response = $http->post('https://openrouter.ai/api/v1/chat/completions', json_encode([
                'model'    => 'google/gemma-4-31b-it:free',
                'messages' => [
                    ['role' => 'system', 'content' => $this->systemPromptProvider->get($role)],
                    ['role' => 'user',   'content' => $prompt],
                ],
                'temperature' => 0.7,
            ]), [
                'headers' => [
                    'Authorization' => 'Bearer ' . $apiKey,
                    'Content-Type' => 'application/json',
                    'HTTP-Referer' => 'https://github.com/kamaho-source/shokusuu1',
                ]
            ]);

            if (!$response->isOk()) {
                Log::error('OpenRouter API error: Status ' . $response->getStatusCode() . ' - ' . $response->getStringBody());
                throw new InternalErrorException('AIからの回答取得に失敗しました。');
            }

            $json   = $response->getJson();
            $answer = $json['choices'][0]['message']['content'] ?? '回答を取得できませんでした。';

            return $this->response->withType('application/json')
                ->withStringBody(json_encode(['ok' => true, 'answer' => $answer]));

        } catch (\Exception $e) {
            Log::error('AI Assistant error: ' . $e->getMessage());
            throw new InternalErrorException('通信エラーが発生しました。');
        }
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
}
