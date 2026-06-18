<?php
declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * 入力値・バリデーション起因の例外（HTTP 400 / 422 に対応）。
 *
 * - 400: JSON デコード失敗など、リクエスト自体が不正
 * - 422: ビジネスルール違反（日付範囲・必須フィールド欠落など）
 */
class InvalidInputException extends DomainException
{
    public function __construct(string $message, private readonly int $statusCode = 422)
    {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
