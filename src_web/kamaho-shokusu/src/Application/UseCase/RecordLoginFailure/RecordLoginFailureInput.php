<?php
declare(strict_types=1);

namespace App\Application\UseCase\RecordLoginFailure;

/**
 * ログインに失敗した試行（入力されたログインID）。
 */
final class RecordLoginFailureInput
{
    public function __construct(
        public readonly string $loginAccount,
        public readonly string $ipAddress,
    ) {}
}
