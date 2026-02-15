<?php
declare(strict_types=1);

namespace App\Service;

use Cake\Http\Response;

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
            ->withStringBody(json_encode($payload, JSON_UNESCAPED_UNICODE));
    }

    public function error(Response $response, string $message, int $status = 400, array $data = []): Response
    {
        return $response
            ->withStatus($status)
            ->withType('application/json')
            ->withStringBody(json_encode([
                'ok' => false,
                'message' => $message,
                'data' => $data,
            ], JSON_UNESCAPED_UNICODE));
    }

    public function forbidden(Response $response, ?string $message = null): Response
    {
        return $this->error($response, $message ?? '権限がありません。', 403);
    }
}
