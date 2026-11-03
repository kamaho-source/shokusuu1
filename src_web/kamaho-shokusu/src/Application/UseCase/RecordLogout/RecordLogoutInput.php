<?php
declare(strict_types=1);

namespace App\Application\UseCase\RecordLogout;

/**
 * ログアウトした人（ログイン中のアカウントの値）。
 */
final class RecordLogoutInput
{
    public function __construct(
        public readonly int $userId,
        public readonly ?string $userName,
        public readonly ?string $loginAccount,
        public readonly string $ipAddress,
    ) {}
}
