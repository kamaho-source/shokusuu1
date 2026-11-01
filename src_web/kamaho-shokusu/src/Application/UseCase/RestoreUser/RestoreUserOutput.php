<?php
declare(strict_types=1);

namespace App\Application\UseCase\RestoreUser;

/**
 * 利用者の復元の結果。
 */
final class RestoreUserOutput
{
    public function __construct(public readonly ?string $userName) {}
}
