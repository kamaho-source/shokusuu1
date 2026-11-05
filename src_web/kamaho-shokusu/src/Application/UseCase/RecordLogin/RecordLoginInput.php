<?php
declare(strict_types=1);

namespace App\Application\UseCase\RecordLogin;

/**
 * ログインに成功した人（認証できたアカウントの値）。
 */
final class RecordLoginInput
{
    public function __construct(
        public readonly int $userId,
        public readonly ?string $userName,
        public readonly ?string $loginAccount,
        public readonly string $ipAddress,
    ) {}
}
