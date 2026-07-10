<?php
declare(strict_types=1);

namespace App\Infrastructure\AI;

use Cake\Log\Log;

/**
 * OpenRouter Chat Completions API のストリーミングクライアント。
 *
 * 呼び出しの所要時間・HTTPステータスのログ記録と、
 * 4xx/5xx（特にレート制限 429）の検出を共通化する。
 */
final class OpenRouterClient
{
    public const RATE_LIMIT_MESSAGE   = '現在AIが混雑しています。しばらく待ってからお試しください。';
    public const GENERIC_ERROR_MESSAGE = '通信エラーが発生しました。';

    private const ENDPOINT            = 'https://openrouter.ai/api/v1/chat/completions';
    private const HTTP_REFERER        = 'https://github.com/kamaho-source/shokusuu1';
    private const CONNECT_TIMEOUT_SEC = 10;
    private const TIMEOUT_SEC         = 60;

    /**
     * @param string $model 使用するモデルID（例: openai/gpt-oss-20b:free）
     * @param string $apiKey OpenRouter APIキー
     */
    public function __construct(
        private readonly string $model,
        private readonly string $apiKey,
    ) {
    }

    /**
     * ストリーミングでチャット補完を実行し、content デルタごとにコールバックを呼ぶ。
     *
     * 推論型モデルが思考中に送る空の content はコールバックに渡さない。
     *
     * @param list<array{role: string, content: string}> $messages チャットメッセージ
     * @param callable(string): void $onContent 空でない content デルタごとに呼ばれる
     * @return array{success: bool, httpCode: int, fullResponse: string, userMessage: ?string}
     *   失敗時は userMessage にユーザー向けメッセージが入る
     */
    public function streamChat(array $messages, callable $onContent): array
    {
        $fullResponse = '';
        $errorBody    = '';
        $lineBuffer   = '';

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => self::ENDPOINT,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode([
                'model'       => $this->model,
                'messages'    => $messages,
                'temperature' => 0.7,
                'stream'      => true,
            ]),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->apiKey,
                'Content-Type: application/json',
                'HTTP-Referer: ' . self::HTTP_REFERER,
            ],
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT_SEC,
            CURLOPT_TIMEOUT        => self::TIMEOUT_SEC,
            CURLOPT_WRITEFUNCTION  => function ($ch, string $data) use (&$fullResponse, &$errorBody, &$lineBuffer, $onContent): int {
                // 4xx/5xx のボディは SSE ではなく JSON エラーのため、コールバックへ流さず蓄積してログに使う
                $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                if ($status >= 400) {
                    $errorBody .= $data;

                    return strlen($data);
                }

                // cURL は SSE の行境界を跨いでコールバックを呼ぶことがあるため、
                // 前回の未完了行と結合してから改行単位で処理する
                $lineBuffer .= $data;
                $lines       = explode("\n", $lineBuffer);
                $lineBuffer  = (string)array_pop($lines);

                foreach ($lines as $line) {
                    $trimmed = trim($line);
                    if ($trimmed === '' || !str_starts_with($trimmed, 'data: ')) {
                        continue;
                    }
                    $payload = substr($trimmed, 6);
                    if ($payload === '[DONE]') {
                        continue;
                    }
                    $decoded = json_decode($payload, true);
                    if (!is_array($decoded)) {
                        continue;
                    }
                    $content = $decoded['choices'][0]['delta']['content'] ?? null;
                    if (is_string($content) && $content !== '') {
                        $fullResponse .= $content;
                        $onContent($content);
                    }
                }

                return strlen($data);
            },
        ]);

        $startedAt  = microtime(true);
        $execResult = curl_exec($ch);
        $duration   = microtime(true) - $startedAt;
        $httpCode   = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        $curlError  = curl_error($ch);
        curl_close($ch);

        Log::info(sprintf(
            'AI OpenRouter stream: status=%d duration=%.3fs model=%s response_length=%d',
            $httpCode,
            $duration,
            $this->model,
            mb_strlen($fullResponse)
        ));

        if ($execResult === false || $curlError !== '') {
            Log::error('AI stream curl error: ' . $curlError);

            return [
                'success'      => false,
                'httpCode'     => $httpCode,
                'fullResponse' => $fullResponse,
                'userMessage'  => self::GENERIC_ERROR_MESSAGE,
            ];
        }

        if ($httpCode >= 400) {
            Log::error(sprintf('AI stream HTTP %d: %s', $httpCode, self::extractApiError($errorBody)));

            return [
                'success'      => false,
                'httpCode'     => $httpCode,
                'fullResponse' => $fullResponse,
                'userMessage'  => $httpCode === 429 ? self::RATE_LIMIT_MESSAGE : self::GENERIC_ERROR_MESSAGE,
            ];
        }

        return [
            'success'      => true,
            'httpCode'     => $httpCode,
            'fullResponse' => $fullResponse,
            'userMessage'  => null,
        ];
    }

    /**
     * OpenRouter のエラーレスポンスボディから人間可読なエラーメッセージを抽出する。
     *
     * @param string $body レスポンスボディ（JSON想定）
     * @return string 抽出したメッセージ（最大500文字）
     */
    public static function extractApiError(string $body): string
    {
        $json    = json_decode($body, true);
        $message = is_array($json)
            ? ($json['error']['metadata']['raw'] ?? $json['error']['message'] ?? '')
            : '';
        if (!is_string($message) || $message === '') {
            $message = $body;
        }

        return mb_substr($message, 0, 500);
    }
}
