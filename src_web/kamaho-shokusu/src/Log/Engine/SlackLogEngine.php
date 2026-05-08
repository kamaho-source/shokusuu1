<?php
declare(strict_types=1);

namespace App\Log\Engine;

use Cake\Http\Client;
use Cake\Log\Engine\BaseLog;

/**
 * エラーレベル以上のログをSlackへ通知するログエンジン。
 *
 * 環境変数 SLACK_ERROR_WEBHOOK にIncoming Webhook URLを設定することで有効になる。
 * テスト時は config['httpClient'] にモックを渡すことで HTTP 通信を差し替えられる。
 */
final class SlackLogEngine extends BaseLog
{
    private Client $httpClient;

    /**
     * @param array $config 設定配列。'httpClient' キーでテスト用クライアントを注入可能。
     */
    public function __construct(array $config = [])
    {
        parent::__construct($config);
        $this->httpClient = $config['httpClient'] ?? new Client();
    }

    /**
     * @param string $level ログレベル
     * @param string $message ログメッセージ
     * @param array $context コンテキスト情報
     */
    public function log($level, $message, array $context = []): void
    {
        $webhookUrl = env('SLACK_ERROR_WEBHOOK', '');
        if ($webhookUrl === '') {
            return;
        }

        $emoji = match ($level) {
            'emergency', 'alert' => ':rotating_light:',
            'critical'           => ':fire:',
            default              => ':warning:',
        };

        $lines = [
            "{$emoji} *[" . strtoupper((string)$level) . '] システムエラーが発生しました*',
            '>*メッセージ:* ' . $message,
            '>*日時:* ' . date('Y-m-d H:i:s'),
        ];

        if (!empty($context['file'])) {
            $fileLine = '>*ファイル:* ' . $context['file'];
            if (!empty($context['line'])) {
                $fileLine .= ' (行: ' . $context['line'] . ')';
            }
            $lines[] = $fileLine;
        }

        if (!empty($context['trace'])) {
            $trace = \is_array($context['trace'])
                ? implode("\n", \array_slice($context['trace'], 0, 5))
                : substr((string)$context['trace'], 0, 500);
            $lines[] = '>*スタックトレース（冒頭）:*';
            $lines[] = ">```{$trace}```";
        }

        $text = implode("\n", $lines);

        try {
            $this->httpClient->post($webhookUrl, json_encode(['text' => $text], JSON_UNESCAPED_UNICODE), ['type' => 'json']);
        } catch (\Throwable) {
        }
    }
}
