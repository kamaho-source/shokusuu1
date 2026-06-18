<?php
declare(strict_types=1);

namespace App\Domain\Exception;

/**
 * ドメイン例外の基底クラス。HTTP ステータスコードを保持する。
 *
 * Controller はこの型で catch し、getStatusCode() でレスポンスを決定する。
 */
abstract class DomainException extends \RuntimeException
{
    abstract public function getStatusCode(): int;
}
