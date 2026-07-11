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
     * gpt-oss 系（harmony 形式）モデルが応答本文へ混入させる `<|...|>` 特殊トークンや
     * 制御文字は除去してからコールバック・fullResponse へ渡す。
     *
     * @param list<array{role: string, content: string}> $messages チャットメッセージ
     * @param callable(string): void $onContent サニタイズ済み content デルタごとに呼ばれる
     * @return array{success: bool, httpCode: int, fullResponse: string, userMessage: ?string}
     *   失敗時は userMessage にユーザー向けメッセージが入る
     */
    public function streamChat(array $messages, callable $onContent): array
    {
        $fullResponse = '';
        $errorBody    = '';
        $lineBuffer   = '';
        // 特殊トークンがデルタ境界で分割される場合に備え、未確定分をここに保持する
        $emitBuffer   = '';

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
            CURLOPT_WRITEFUNCTION  => function ($ch, string $data) use (&$fullResponse, &$errorBody, &$lineBuffer, &$emitBuffer, $onContent): int {
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
                        $emitBuffer .= $content;
                        $clean       = self::drainSanitized($emitBuffer, false);
                        if ($clean !== '') {
                            $fullResponse .= $clean;
                            $onContent($clean);
                        }
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

        // 末尾に保留していた未確定分を確定・サニタイズして出力する
        $tail = self::drainSanitized($emitBuffer, true);
        if ($tail !== '') {
            $fullResponse .= $tail;
            $onContent($tail);
        }

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

    /**
     * ストリーミング中の蓄積テキストから、外部へ出力してよい安全な部分を切り出す。
     *
     * gpt-oss 系（harmony 形式）モデルが混入させる `<|...|>` 特殊トークンと制御文字を除去する。
     * トークンがデルタ境界で分割されると 1 回のデルタでは完結しないため、末尾に未完の
     * `<|...`（または単独の `<`）が残る場合はその手前までを確定分とし、残りは次回へ持ち越す。
     *
     * @param string $buffer 未確定の蓄積テキスト（参照渡し。確定分は取り除かれる）
     * @param bool   $flush  true の場合は末尾の未完トークンも確定扱いにして全て出力する
     * @return string 出力してよいサニタイズ済みテキスト
     */
    private static function drainSanitized(string &$buffer, bool $flush): string
    {
        // 完結した特殊トークン（例: <|channel|> <|message|> <|end|>）を除去する
        $buffer = preg_replace('/<\|[^>]*\|>/u', '', $buffer) ?? $buffer;

        if ($flush) {
            // 未完のまま終わった特殊トークンの残骸（"<|..." や末尾の単独 "<"）も除去する
            $buffer = preg_replace('/<\|[^>]*$/u', '', $buffer) ?? $buffer;
            $emit   = rtrim($buffer, '<');
            $buffer = '';
        } else {
            // 末尾に未完トークンの開始（"<|..." もしくは単独の "<"）が残る場合は保留する
            $cut  = strlen($buffer);
            $open = strrpos($buffer, '<|');
            if ($open !== false && strpos($buffer, '|>', $open) === false) {
                $cut = $open;
            } elseif (str_ends_with($buffer, '<')) {
                $cut = strlen($buffer) - 1;
            }
            $emit   = substr($buffer, 0, $cut);
            $buffer = substr($buffer, $cut);
        }

        return self::stripControlChars($emit);
    }

    /**
     * 制御文字・ゼロ幅文字など、表示に不要で「謎の文字」の原因となる文字を除去する。
     *
     * 改行（\n）・タブ（\t）は Markdown 表示に必要なため残す。
     *
     * @param string $text 対象テキスト
     * @return string 除去後のテキスト
     */
    private static function stripControlChars(string $text): string
    {
        // C0（\n・\t 以外）・C1 制御文字、ゼロ幅スペース/接合子、BOM を除去する
        return preg_replace(
            '/[\x{0000}-\x{0008}\x{000B}\x{000C}\x{000E}-\x{001F}\x{007F}\x{0080}-\x{009F}\x{200B}-\x{200D}\x{FEFF}]/u',
            '',
            $text
        ) ?? $text;
    }
}
