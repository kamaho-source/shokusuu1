<?php
declare(strict_types=1);

namespace App\Log\Engine;

use Cake\Http\Client;
use Cake\Log\Engine\BaseLog;

/**
 * エラーレベル以上のログをSlackへ通知するログエンジン。
 *
 * 環境変数 SLACK_ERROR_WEBHOOK にIncoming Webhook URLを設定することで有効になる。
 */
final class SlackLogEngine extends BaseLog
{
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
            'critical' => ':fire:',
            default => ':warning:',
        };

        $lines = [
            $emoji . ' *[' . strtoupper((string)$level) . '] システムエラーが発生しました*',
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
            $trace = is_array($context['trace'])
                ? implode("\n", array_slice($context['trace'], 0, 5))
                : substr((string)$context['trace'], 0, 500);
            $lines[] = '>*スタックトレース（冒頭）:*';
            $lines[] = '>```' . $trace . '```';
        }

        $text = implode("\n", $lines);

        try {
            $http = new Client();
            $http->post($webhookUrl, json_encode(['text' => $text]), ['type' => 'json']);
        } catch (\Throwable) {
        }
    }
}
