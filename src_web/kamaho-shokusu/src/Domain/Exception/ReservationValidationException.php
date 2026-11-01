<?php
declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * 予約の新規保存が検証ルールに当たった（HTTP 422 に対応）。
 *
 * メッセージは検証エラーの JSON（ログ用）。利用者に返す文言は呼び出し側が決める。
 */
final class ReservationValidationException extends DomainException
{
    /**
     * @param string|null  $lunchBentoMessage 昼食と弁当の同時予約の検証ルールに当たったときの理由（それ以外は null）
     * @param list<string> $errorMessages     検証エラーの文言（項目・ルールを問わず並べたもの）
     * @param string       $errorsJson        検証エラーの JSON
     */
    public function __construct(
        public readonly ?string $lunchBentoMessage,
        public readonly array $errorMessages,
        string $errorsJson,
    ) {
        parent::__construct($errorsJson);
    }

    public function getStatusCode(): int
    {
        return 422;
    }
}
