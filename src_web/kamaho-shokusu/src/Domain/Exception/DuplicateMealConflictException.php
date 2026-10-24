<?php
declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * 同じ日の同じ食事が、別の部屋ですでに予約されている（HTTP 409 に対応）。
 *
 * 一括の直接登録では、この食事だけをスキップして残りを登録する。
 */
final class DuplicateMealConflictException extends DomainException
{
    public function __construct(string $message = '同じ日の同じ食事が、別の部屋ですでに予約されています。画面を再読み込みしてください。')
    {
        parent::__construct($message);
    }

    public function getStatusCode(): int
    {
        return 409;
    }
}
