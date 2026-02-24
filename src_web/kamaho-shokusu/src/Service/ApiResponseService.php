<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Http\Response;
use Cake\Log\Log;

class ApiResponseService
{
    public function success(Response $response, array $data = [], ?string $message = null, int $status = 200): Response
    {
        $payload = [
            'ok' => true,
            'data' => $data,
        ];
        if ($message !== null && $message !== '') {
            $payload['message'] = $message;
        }

        return $response
            ->withStatus($status)
            ->withType('application/json')
            ->withStringBody($this->encode($payload));
    }

    public function error(Response $response, string $message, int $status = 400, array $data = []): Response
    {
        return $response
            ->withStatus($status)
            ->withType('application/json')
            ->withStringBody($this->encode([
                'ok' => false,
                'message' => $message,
                'data' => $data,
            ]));
    }

    public function forbidden(Response $response, ?string $message = null): Response
    {
        return $this->error($response, $message ?? '権限がありません。', 403);
    }

    /**
     * 配列を JSON 文字列にエンコードする。
     * エンコード失敗時はエラーをログに記録し、汎用エラー JSON を返す。
     */
    private function encode(array $payload): string
    {
        try {
            return json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            Log::error('ApiResponseService: json_encode failed: ' . $e->getMessage());
            return '{"ok":false,"message":"レスポンスの生成に失敗しました。"}';
        }
    }
}
