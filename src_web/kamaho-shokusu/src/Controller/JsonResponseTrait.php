<?php
declare(strict_types=1);

namespace App\Controller;

use Cake\Http\Response;

/**
 * JSON API 用のレスポンス生成（success/error の共通形式）。
 *
 * 利用側は $this->response を持つ Controller であること。
 */
trait JsonResponseTrait
{
    private function jsonResponse(array $data, int $status = 200): Response
    {
        return $this->response
            ->withStatus($status)
            ->withType('application/json')
            ->withStringBody((string)json_encode($data, JSON_UNESCAPED_UNICODE));
    }

    private function jsonError(string $message, int $status = 400): Response
    {
        return $this->jsonResponse(['success' => false, 'error' => $message], $status);
    }
}
